<?php
declare(strict_types=1);

namespace Tests\Unit\Validators;

use App\Validators\AnmeldungValidator;
use PHPUnit\Framework\TestCase;

/**
 * Tests for AnmeldungValidator - especially SQL injection prevention
 */
class AnmeldungValidatorTest extends TestCase
{
    /**
     * Test that null formular name is accepted (no filter)
     */
    public function testValidateFormularNameAcceptsNull(): void
    {
        // Should not throw exception
        AnmeldungValidator::validateFormularName(null);
        $this->assertTrue(true); // Assert that no exception was thrown
    }

    /**
     * Test that empty string formular name is accepted (no filter)
     */
    public function testValidateFormularNameAcceptsEmptyString(): void
    {
        // Should not throw exception
        AnmeldungValidator::validateFormularName('');
        $this->assertTrue(true); // Assert that no exception was thrown
    }

    /**
     * Test that valid formular names are accepted
     */
    public function testValidateFormularNameAcceptsValidNames(): void
    {
        $validNames = [
            'bs',
            'bk',
            'form123',
            'test-form',
            'test_form',
            'Form-123_Test',
        ];

        foreach ($validNames as $name) {
            // Should not throw exception
            AnmeldungValidator::validateFormularName($name);
        }

        $this->assertTrue(true); // Assert that no exception was thrown
    }

    /**
     * Test that SQL injection attempts are blocked
     */
    public function testValidateFormularNameRejectsSqlInjection(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Ungültiger Formularname');

        // Try SQL injection
        AnmeldungValidator::validateFormularName("bs' OR '1'='1");
    }

    /**
     * Test that semicolons are blocked (SQL command separator)
     */
    public function testValidateFormularNameRejectsSemicolon(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Ungültiger Formularname');

        AnmeldungValidator::validateFormularName('bs;DROP TABLE anmeldungen');
    }

    /**
     * Test that hyphens are allowed (including double hyphens)
     *
     * Note: We allow hyphens in formular names (e.g., "form-name").
     * SQL comments like "--" are only dangerous in raw SQL strings, but we use
     * Prepared Statements throughout the application, making this safe.
     */
    public function testValidateFormularNameAllowsHyphens(): void
    {
        // Hyphens (including double) are allowed for formular names
        AnmeldungValidator::validateFormularName('form-name');
        AnmeldungValidator::validateFormularName('test--double');
        AnmeldungValidator::validateFormularName('bs--comment'); // Safe with Prepared Statements

        $this->assertTrue(true); // Assert that no exception was thrown
    }

    /**
     * Test that hash (another SQL comment) is blocked
     */
    public function testValidateFormularNameRejectsHashComment(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Ungültiger Formularname');

        AnmeldungValidator::validateFormularName('bs#comment');
    }

    /**
     * Test that special characters are blocked
     */
    public function testValidateFormularNameRejectsSpecialCharacters(): void
    {
        $invalidNames = [
            'bs@test',
            'bs!test',
            'bs$test',
            'bs%test',
            'bs&test',
            'bs*test',
            'bs(test)',
            'bs[test]',
            'bs{test}',
            'bs|test',
            'bs\\test',
            'bs/test',
            'bs<test>',
            'bs,test',
            'bs.test',
            'bs?test',
            'bs test', // space
            "bs'test", // single quote
            'bs"test', // double quote
            'bs`test', // backtick
        ];

        foreach ($invalidNames as $name) {
            try {
                AnmeldungValidator::validateFormularName($name);
                $this->fail("Expected exception for invalid name: $name");
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString('Ungültiger Formularname', $e->getMessage());
            }
        }
    }

    /**
     * Test that path traversal attempts are blocked
     */
    public function testValidateFormularNameRejectsPathTraversal(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Ungültiger Formularname');

        AnmeldungValidator::validateFormularName('../../../etc/passwd');
    }

    /**
     * Test that very long names are rejected
     */
    public function testValidateFormularNameRejectsLongNames(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('zu lang');

        // 51 characters (max is 50)
        $longName = str_repeat('a', 51);
        AnmeldungValidator::validateFormularName($longName);
    }

    /**
     * Test that 50 character names are accepted (boundary test)
     */
    public function testValidateFormularNameAccepts50Characters(): void
    {
        // Exactly 50 characters (should be accepted)
        $maxName = str_repeat('a', 50);
        AnmeldungValidator::validateFormularName($maxName);

        $this->assertTrue(true); // Assert that no exception was thrown
    }

    /**
     * Test that unicode characters are blocked
     */
    public function testValidateFormularNameRejectsUnicode(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Ungültiger Formularname');

        AnmeldungValidator::validateFormularName('test_ä');
    }

    /**
     * Test that NULL bytes are blocked (prevents null byte injection)
     */
    public function testValidateFormularNameRejectsNullByte(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Ungültiger Formularname');

        AnmeldungValidator::validateFormularName("test\0injection");
    }

    // ========================================================================
    // File Validation Tests
    // ========================================================================

    private string $testFilesDir;

    protected function setUp(): void
    {
        parent::setUp();
        // Create temporary directory for test files
        $this->testFilesDir = sys_get_temp_dir() . '/validator_test_' . uniqid();
        mkdir($this->testFilesDir, 0755, true);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        // Clean up test files
        if (is_dir($this->testFilesDir)) {
            $files = glob($this->testFilesDir . '/*');
            foreach ($files as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
            rmdir($this->testFilesDir);
        }
    }

    /**
     * Test validateFileSize with valid file
     */
    public function testValidateFileSizeAcceptsValidFile(): void
    {
        $validator = new AnmeldungValidator();
        $file = ['size' => 1024]; // 1KB

        $result = $validator->validateFileSize($file, 10485760); // 10MB max

        $this->assertTrue($result['success']);
        $this->assertArrayNotHasKey('error', $result);
    }

    /**
     * Test validateFileSize rejects file that's too large
     */
    public function testValidateFileSizeRejectsLargeFile(): void
    {
        $validator = new AnmeldungValidator();
        $file = ['size' => 11 * 1048576]; // 11MB

        $result = $validator->validateFileSize($file, 10485760); // 10MB max

        $this->assertFalse($result['success']);
        $this->assertArrayHasKey('error', $result);
        $this->assertStringContainsString('zu groß', $result['error']);
    }

    /**
     * Test validateFileSize rejects empty file
     */
    public function testValidateFileSizeRejectsEmptyFile(): void
    {
        $validator = new AnmeldungValidator();
        $file = ['size' => 0];

        $result = $validator->validateFileSize($file);

        $this->assertFalse($result['success']);
        $this->assertArrayHasKey('error', $result);
        $this->assertStringContainsString('leer', $result['error']);
    }

    /**
     * Test validateFileSize with missing size field
     */
    public function testValidateFileSizeRejectsMissingSize(): void
    {
        $validator = new AnmeldungValidator();
        $file = [];

        $result = $validator->validateFileSize($file);

        $this->assertFalse($result['success']);
        $this->assertArrayHasKey('error', $result);
    }

    /**
     * Test validateMimeType accepts PDF
     */
    public function testValidateMimeTypeAcceptsPdf(): void
    {
        $validator = new AnmeldungValidator();

        // Create a minimal valid PDF
        $pdfContent = "%PDF-1.4\n1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj 2 0 obj<</Type/Pages/Kids[3 0 R]/Count 1>>endobj 3 0 obj<</Type/Page/MediaBox[0 0 612 792]/Parent 2 0 R/Resources<<>>>>endobj\nxref\n0 4\n0000000000 65535 f\n0000000009 00000 n\n0000000058 00000 n\n0000000115 00000 n\ntrailer<</Size 4/Root 1 0 R>>\nstartxref\n190\n%%EOF";
        $testFile = $this->testFilesDir . '/test.pdf';
        file_put_contents($testFile, $pdfContent);

        $file = ['tmp_name' => $testFile];

        $result = $validator->validateMimeType($file);

        $this->assertTrue($result['success']);
        $this->assertEquals('application/pdf', $result['mime_type']);
    }

    /**
     * Test validateMimeType accepts PNG
     */
    public function testValidateMimeTypeAcceptsPng(): void
    {
        $validator = new AnmeldungValidator();

        // Create a minimal valid PNG (1x1 transparent pixel)
        $pngContent = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==');
        $testFile = $this->testFilesDir . '/test.png';
        file_put_contents($testFile, $pngContent);

        $file = ['tmp_name' => $testFile];

        $result = $validator->validateMimeType($file);

        $this->assertTrue($result['success']);
        $this->assertEquals('image/png', $result['mime_type']);
    }

    /**
     * Test validateMimeType accepts JPEG
     */
    public function testValidateMimeTypeAcceptsJpeg(): void
    {
        $validator = new AnmeldungValidator();

        // Create a minimal valid JPEG
        $jpegContent = base64_decode('/9j/4AAQSkZJRgABAQEAYABgAAD/2wBDAAgGBgcGBQgHBwcJCQgKDBQNDAsLDBkSEw8UHRofHh0aHBwgJC4nICIsIxwcKDcpLDAxNDQ0Hyc5PTgyPC4zNDL/2wBDAQkJCQwLDBgNDRgyIRwhMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjL/wAARCAABAAEDASIAAhEBAxEB/8QAFQABAQAAAAAAAAAAAAAAAAAAAAv/xAAUEAEAAAAAAAAAAAAAAAAAAAAA/8QAFQEBAQAAAAAAAAAAAAAAAAAAAAX/xAAUEQEAAAAAAAAAAAAAAAAAAAAA/9oADAMBAAIRAxEAPwCwAA8A/9k=');
        $testFile = $this->testFilesDir . '/test.jpg';
        file_put_contents($testFile, $jpegContent);

        $file = ['tmp_name' => $testFile];

        $result = $validator->validateMimeType($file);

        $this->assertTrue($result['success']);
        $this->assertEquals('image/jpeg', $result['mime_type']);
    }

    /**
     * Test validateMimeType rejects text file disguised as PDF
     */
    public function testValidateMimeTypeRejectsDisguisedFile(): void
    {
        $validator = new AnmeldungValidator();

        // Create a text file (not a real PDF)
        $testFile = $this->testFilesDir . '/fake.pdf';
        file_put_contents($testFile, 'This is just plain text, not a PDF');

        $file = ['tmp_name' => $testFile];

        $result = $validator->validateMimeType($file);

        $this->assertFalse($result['success']);
        $this->assertArrayHasKey('error', $result);
        $this->assertStringContainsString('nicht erlaubt', $result['error']);
    }

    /**
     * Test validateMimeType rejects PHP file disguised as image
     */
    public function testValidateMimeTypeRejectsPhpFile(): void
    {
        $validator = new AnmeldungValidator();

        // Create a PHP file (potential security risk)
        $testFile = $this->testFilesDir . '/evil.jpg';
        file_put_contents($testFile, '<?php system($_GET["cmd"]); ?>');

        $file = ['tmp_name' => $testFile];

        $result = $validator->validateMimeType($file);

        $this->assertFalse($result['success']);
        $this->assertArrayHasKey('error', $result);
    }

    /**
     * Test validateMimeType with missing tmp_name
     */
    public function testValidateMimeTypeRejectsMissingTmpName(): void
    {
        $validator = new AnmeldungValidator();
        $file = [];

        $result = $validator->validateMimeType($file);

        $this->assertFalse($result['success']);
        $this->assertArrayHasKey('error', $result);
    }

    /**
     * Test validateExtension accepts valid PDF extension
     */
    public function testValidateExtensionAcceptsValidPdfExtension(): void
    {
        $validator = new AnmeldungValidator();
        $file = ['name' => 'document.pdf'];

        $result = $validator->validateExtension($file, 'application/pdf');

        $this->assertTrue($result['success']);
        $this->assertEquals('pdf', $result['extension']);
    }

    /**
     * Test validateExtension accepts valid image extensions
     */
    public function testValidateExtensionAcceptsValidImageExtensions(): void
    {
        $validator = new AnmeldungValidator();

        $testCases = [
            ['name' => 'photo.jpg', 'mime' => 'image/jpeg', 'ext' => 'jpg'],
            ['name' => 'photo.jpeg', 'mime' => 'image/jpeg', 'ext' => 'jpeg'],
            ['name' => 'image.png', 'mime' => 'image/png', 'ext' => 'png'],
            ['name' => 'animation.gif', 'mime' => 'image/gif', 'ext' => 'gif'],
            ['name' => 'modern.webp', 'mime' => 'image/webp', 'ext' => 'webp'],
        ];

        foreach ($testCases as $testCase) {
            $file = ['name' => $testCase['name']];
            $result = $validator->validateExtension($file, $testCase['mime']);

            $this->assertTrue($result['success'], "Failed for {$testCase['name']}");
            $this->assertEquals($testCase['ext'], $result['extension']);
        }
    }

    /**
     * Test validateExtension is case-insensitive
     */
    public function testValidateExtensionIsCaseInsensitive(): void
    {
        $validator = new AnmeldungValidator();

        $testCases = [
            ['filename' => 'document.PDF', 'mime' => 'application/pdf'],
            ['filename' => 'document.Pdf', 'mime' => 'application/pdf'],
            ['filename' => 'document.pDf', 'mime' => 'application/pdf'],
            ['filename' => 'photo.JPG', 'mime' => 'image/jpeg'],
            ['filename' => 'photo.Jpg', 'mime' => 'image/jpeg'],
            ['filename' => 'image.PNG', 'mime' => 'image/png'],
            ['filename' => 'image.Png', 'mime' => 'image/png'],
        ];

        foreach ($testCases as $testCase) {
            $file = ['name' => $testCase['filename']];
            $result = $validator->validateExtension($file, $testCase['mime']);

            $this->assertTrue($result['success'], "Failed for {$testCase['filename']}");
        }
    }

    /**
     * Test validateExtension rejects mismatched extension
     */
    public function testValidateExtensionRejectsMismatchedExtension(): void
    {
        $validator = new AnmeldungValidator();

        // File has .jpg extension but MIME type says it's a PDF
        $file = ['name' => 'document.jpg'];

        $result = $validator->validateExtension($file, 'application/pdf');

        $this->assertFalse($result['success']);
        $this->assertArrayHasKey('error', $result);
        $this->assertStringContainsString('stimmt nicht', $result['error']);
    }

    /**
     * Test validateExtension rejects double extension attack
     */
    public function testValidateExtensionRejectsDoubleExtension(): void
    {
        $validator = new AnmeldungValidator();

        // Attacker tries evil.php.jpg (only .jpg is extracted by pathinfo)
        $file = ['name' => 'evil.php.jpg'];

        $result = $validator->validateExtension($file, 'text/x-php');

        $this->assertFalse($result['success']);
        $this->assertArrayHasKey('error', $result);
    }

    /**
     * Test validateExtension rejects missing extension
     */
    public function testValidateExtensionRejectsMissingExtension(): void
    {
        $validator = new AnmeldungValidator();
        $file = ['name' => 'document'];

        $result = $validator->validateExtension($file, 'application/pdf');

        $this->assertFalse($result['success']);
        $this->assertArrayHasKey('error', $result);
        $this->assertStringContainsString('fehlt', $result['error']);
    }

    /**
     * Test validateExtension with missing filename
     */
    public function testValidateExtensionRejectsMissingFilename(): void
    {
        $validator = new AnmeldungValidator();
        $file = [];

        $result = $validator->validateExtension($file, 'application/pdf');

        $this->assertFalse($result['success']);
        $this->assertArrayHasKey('error', $result);
    }

    /**
     * Test validateFile integration (all checks pass)
     */
    public function testValidateFileAcceptsValidPdf(): void
    {
        $validator = new AnmeldungValidator();

        // Create a minimal valid PDF
        $pdfContent = "%PDF-1.4\n1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj 2 0 obj<</Type/Pages/Kids[3 0 R]/Count 1>>endobj 3 0 obj<</Type/Page/MediaBox[0 0 612 792]/Parent 2 0 R/Resources<<>>>>endobj\nxref\n0 4\n0000000000 65535 f\n0000000009 00000 n\n0000000058 00000 n\n0000000115 00000 n\ntrailer<</Size 4/Root 1 0 R>>\nstartxref\n190\n%%EOF";
        $testFile = $this->testFilesDir . '/test.pdf';
        file_put_contents($testFile, $pdfContent);

        $file = [
            'error' => UPLOAD_ERR_OK,
            'size' => filesize($testFile),
            'tmp_name' => $testFile,
            'name' => 'test.pdf',
        ];

        $result = $validator->validateFile($file);

        $this->assertTrue($result['success']);
        $this->assertEquals('application/pdf', $result['mime_type']);
        $this->assertEquals('pdf', $result['extension']);
    }

    /**
     * Test validateFile rejects file with upload error
     */
    public function testValidateFileRejectsUploadError(): void
    {
        $validator = new AnmeldungValidator();
        $file = ['error' => UPLOAD_ERR_NO_FILE];

        $result = $validator->validateFile($file);

        $this->assertFalse($result['success']);
        $this->assertArrayHasKey('error', $result);
    }

    /**
     * Test validateFile rejects oversized file
     */
    public function testValidateFileRejectsOversizedFile(): void
    {
        $validator = new AnmeldungValidator();

        $testFile = $this->testFilesDir . '/large.pdf';
        file_put_contents($testFile, str_repeat('x', 1024)); // 1KB

        $file = [
            'error' => UPLOAD_ERR_OK,
            'size' => 1024,
            'tmp_name' => $testFile,
            'name' => 'large.pdf',
        ];

        // Set max size to 512 bytes (smaller than file)
        $result = $validator->validateFile($file, 512);

        $this->assertFalse($result['success']);
        $this->assertArrayHasKey('error', $result);
        $this->assertStringContainsString('zu groß', $result['error']);
    }

    /**
     * Test validateFile rejects PHP file disguised as image
     */
    public function testValidateFileRejectsDisguisedPhpFile(): void
    {
        $validator = new AnmeldungValidator();

        $testFile = $this->testFilesDir . '/evil.jpg';
        file_put_contents($testFile, '<?php system($_GET["cmd"]); ?>');

        $file = [
            'error' => UPLOAD_ERR_OK,
            'size' => filesize($testFile),
            'tmp_name' => $testFile,
            'name' => 'evil.jpg',
        ];

        $result = $validator->validateFile($file);

        $this->assertFalse($result['success']);
        $this->assertArrayHasKey('error', $result);
    }

    /**
     * Test getAllowedMimeTypes returns correct structure
     */
    public function testGetAllowedMimeTypesReturnsCorrectStructure(): void
    {
        $mimeTypes = AnmeldungValidator::getAllowedMimeTypes();

        $this->assertIsArray($mimeTypes);
        $this->assertNotEmpty($mimeTypes);

        // Check that each MIME type has extensions
        foreach ($mimeTypes as $mime => $extensions) {
            $this->assertIsString($mime);
            $this->assertIsArray($extensions);
            $this->assertNotEmpty($extensions);
        }
    }

    /**
     * Test that dangerous file types are excluded
     */
    public function testGetAllowedMimeTypesExcludesDangerousTypes(): void
    {
        $mimeTypes = AnmeldungValidator::getAllowedMimeTypes();

        // doc/docx should NOT be in the list by default (security risk)
        $this->assertArrayNotHasKey('application/msword', $mimeTypes);
        $this->assertArrayNotHasKey('application/vnd.openxmlformats-officedocument.wordprocessingml.document', $mimeTypes);

        // Executable types should NOT be allowed
        $this->assertArrayNotHasKey('application/x-executable', $mimeTypes);
        $this->assertArrayNotHasKey('application/x-sh', $mimeTypes);
    }
}
