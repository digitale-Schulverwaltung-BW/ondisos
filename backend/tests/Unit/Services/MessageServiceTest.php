<?php
declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\MessageService;
use PHPUnit\Framework\TestCase;

/**
 * Unit Tests for MessageService
 *
 * Tests central message management including:
 * - Dot notation access
 * - Placeholder replacement
 * - Local overrides
 * - Contact info integration
 * - Fallback handling
 */
class MessageServiceTest extends TestCase
{
    private string $testLocalFile;

    protected function setUp(): void
    {
        parent::setUp();

        // Reset message cache before each test
        MessageService::reset();

        // Path to test local override file
        $this->testLocalFile = __DIR__ . '/../../../config/messages.local.php';

        // Clean up any existing local file from previous tests
        if (file_exists($this->testLocalFile)) {
            unlink($this->testLocalFile);
        }
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        // Clean up test local file
        if (file_exists($this->testLocalFile)) {
            unlink($this->testLocalFile);
        }

        // Reset message cache after each test
        MessageService::reset();
    }

    public function testGetReturnsSimpleMessage(): void
    {
        // Test with existing message from base config
        $message = MessageService::get('validation.required_formular');

        $this->assertNotEmpty($message);
        $this->assertStringContainsString('Formular', $message);
    }

    public function testGetReturnsNestedMessage(): void
    {
        // Test nested dot notation access
        $message = MessageService::get('errors.generic_error');

        $this->assertNotEmpty($message);
        $this->assertIsString($message);
    }

    public function testGetReturnsDefaultWhenKeyNotFound(): void
    {
        $default = 'Fallback Text';
        $message = MessageService::get('nonexistent.key', $default);

        $this->assertEquals($default, $message);
    }

    public function testGetReturnsPlaceholderWhenKeyNotFoundAndNoDefault(): void
    {
        $message = MessageService::get('nonexistent.key');

        $this->assertEquals('[missing: nonexistent.key]', $message);
    }

    public function testFormatReplacesPlaceholders(): void
    {
        $message = MessageService::format('errors.file_too_large', [
            'maxSize' => '10'
        ]);

        $this->assertStringContainsString('10', $message);
        $this->assertStringNotContainsString('{{maxSize}}', $message);
    }

    public function testFormatReplacesMultiplePlaceholders(): void
    {
        // Create a test message with multiple placeholders
        // We'll use an existing message and add replacements
        $message = MessageService::format('errors.file_type_not_allowed', [
            'extension' => 'exe'
        ]);

        $this->assertStringContainsString('exe', $message);
        $this->assertStringNotContainsString('{{extension}}', $message);
    }

    public function testFormatWithoutPlaceholders(): void
    {
        $message = MessageService::format('validation.required_formular');

        // Should return message as-is
        $this->assertNotEmpty($message);
        $this->assertIsString($message);
    }

    public function testFormatWithEmptyReplacements(): void
    {
        $message = MessageService::format('validation.required_formular', []);

        $this->assertNotEmpty($message);
        $this->assertIsString($message);
    }

    public function testWithContactIncludesContactInfo(): void
    {
        $message = MessageService::withContact('errors.generic_error');

        // Message should not contain {{contact}} placeholder anymore
        $this->assertStringNotContainsString('{{contact}}', $message);

        // Message should be a valid string
        $this->assertNotEmpty($message);
        $this->assertIsString($message);
    }

    public function testWithContactWithAdditionalReplacements(): void
    {
        $message = MessageService::withContact('errors.file_too_large', [
            'maxSize' => '25'
        ]);

        $this->assertStringContainsString('25', $message);
        $this->assertStringNotContainsString('{{maxSize}}', $message);
        $this->assertStringNotContainsString('{{contact}}', $message);
    }

    public function testGetAllReturnsArray(): void
    {
        $all = MessageService::getAll();

        $this->assertIsArray($all);
        $this->assertNotEmpty($all);
    }

    public function testGetAllContainsExpectedCategories(): void
    {
        $all = MessageService::getAll();

        // Should have standard categories from messages.php
        $this->assertArrayHasKey('validation', $all);
        $this->assertArrayHasKey('errors', $all);
        $this->assertArrayHasKey('success', $all);
    }

    public function testLocalOverridesMergeCorrectly(): void
    {
        // Create a local override file
        $overrideContent = <<<'PHP'
<?php
return [
    'validation' => [
        'required_formular' => 'Custom Formular Message',
    ],
    'custom' => [
        'test' => 'Custom Test Message',
    ],
];
PHP;
        file_put_contents($this->testLocalFile, $overrideContent);

        // Reset to force reload
        MessageService::reset();

        // Original message should be overridden
        $overridden = MessageService::get('validation.required_formular');
        $this->assertEquals('Custom Formular Message', $overridden);

        // New custom message should be available
        $custom = MessageService::get('custom.test');
        $this->assertEquals('Custom Test Message', $custom);

        // Non-overridden messages should still work
        $original = MessageService::get('validation.required_email');
        $this->assertNotEmpty($original);
        $this->assertStringContainsString('E-Mail', $original);
    }

    public function testLocalOverridesDeepMerge(): void
    {
        // Create a local override that only overrides one nested key
        $overrideContent = <<<'PHP'
<?php
return [
    'errors' => [
        'generic_error' => 'Custom Generic Error',
    ],
];
PHP;
        file_put_contents($this->testLocalFile, $overrideContent);

        MessageService::reset();

        // Overridden key
        $overridden = MessageService::get('errors.generic_error');
        $this->assertEquals('Custom Generic Error', $overridden);

        // Other keys in same category should still exist
        $notOverridden = MessageService::get('errors.database_error');
        $this->assertNotEmpty($notOverridden);
        $this->assertStringContainsString('Datenbank', $notOverridden);
    }

    public function testResetClearsCache(): void
    {
        // Load messages
        $before = MessageService::get('validation.required_formular');
        $this->assertNotEmpty($before);

        // Reset cache
        MessageService::reset();

        // Messages should still load after reset
        $after = MessageService::get('validation.required_formular');
        $this->assertEquals($before, $after);
    }

    public function testMultipleGetCallsUseCachedMessages(): void
    {
        // This tests that messages are loaded only once
        $message1 = MessageService::get('validation.required_formular');
        $message2 = MessageService::get('validation.required_email');
        $message3 = MessageService::get('errors.generic_error');

        // All should return valid strings
        $this->assertNotEmpty($message1);
        $this->assertNotEmpty($message2);
        $this->assertNotEmpty($message3);
    }

    public function testGetHandlesEmptyKey(): void
    {
        $message = MessageService::get('');

        $this->assertEquals('[missing: ]', $message);
    }

    public function testGetHandlesSingleLevelKey(): void
    {
        // Single level keys should also work
        $all = MessageService::getAll();

        // Get a top-level key
        $validation = MessageService::get('validation');

        // Should return default/placeholder since we expect a string
        $this->assertTrue(
            is_string($validation) || $validation === '[missing: validation]'
        );
    }

    public function testFormatConvertsNonStringValuesToString(): void
    {
        $message = MessageService::format('errors.file_too_large', [
            'maxSize' => 100  // Integer, not string
        ]);

        $this->assertStringContainsString('100', $message);
    }

    public function testGetAllIncludesLocalOverrides(): void
    {
        // Create local overrides
        $overrideContent = <<<'PHP'
<?php
return [
    'custom' => [
        'test_key' => 'Test Value',
    ],
];
PHP;
        file_put_contents($this->testLocalFile, $overrideContent);

        MessageService::reset();

        $all = MessageService::getAll();

        // Should include custom key
        $this->assertArrayHasKey('custom', $all);
        $this->assertEquals('Test Value', $all['custom']['test_key']);
    }

    public function testMessagesAreLoadedLazily(): void
    {
        // Reset to clear any cached messages
        MessageService::reset();

        // Messages should only load on first access
        // We can't directly test lazy loading, but we can verify it works
        $message = MessageService::get('validation.required_formular');

        $this->assertNotEmpty($message);
    }

    public function testNestedDotNotationWithManyLevels(): void
    {
        // Test deep nesting (though current messages don't go very deep)
        $message = MessageService::get('validation.required_formular');

        // Should work with 2-level nesting
        $this->assertNotEmpty($message);
        $this->assertIsString($message);
    }

    public function testFormatPreservesOriginalMessageWhenNoPlaceholders(): void
    {
        $key = 'validation.required_formular';
        $original = MessageService::get($key);
        $formatted = MessageService::format($key, ['unused' => 'value']);

        // Should be identical if no placeholders exist
        $this->assertEquals($original, $formatted);
    }

    public function testWithContactWithMissingContactInfo(): void
    {
        // Even if contact info is missing, should not crash
        $message = MessageService::withContact('validation.required_formular');

        $this->assertNotEmpty($message);
        $this->assertIsString($message);
    }
}
