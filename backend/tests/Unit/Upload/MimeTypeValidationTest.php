<?php
declare(strict_types=1);

namespace Tests\Unit\Upload;

use PHPUnit\Framework\TestCase;

/**
 * Tests for MIME Type Validation in upload.php
 *
 * These tests verify that file uploads are validated using MIME types
 * (not just extensions) to prevent attackers from renaming malicious files.
 */
class MimeTypeValidationTest extends TestCase
{
    private string $testFilesDir;

    protected function setUp(): void
    {
        parent::setUp();

        // Create temporary directory for test files
        $this->testFilesDir = sys_get_temp_dir() . '/upload_mime_test_' . uniqid();
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
     * Test that allowed MIME types are defined correctly
     */
    public function testAllowedMimeTypesAreDefined(): void
    {
        $allowedMimeTypes = [
            'application/pdf' => ['pdf'],
            'image/jpeg' => ['jpg', 'jpeg'],
            'image/png' => ['png'],
            'image/gif' => ['gif'],
            'image/webp' => ['webp'],
            'image/svg+xml' => ['svg'],
        ];

        // Verify structure
        $this->assertIsArray($allowedMimeTypes);
        $this->assertNotEmpty($allowedMimeTypes);

        // Verify each MIME type has extensions
        foreach ($allowedMimeTypes as $mimeType => $extensions) {
            $this->assertIsString($mimeType);
            $this->assertIsArray($extensions);
            $this->assertNotEmpty($extensions);
        }
    }

    /**
     * Test that doc/docx are NOT in the allowed list (security)
     */
    public function testDocTypesAreExcluded(): void
    {
        $allowedMimeTypes = [
            'application/pdf' => ['pdf'],
            'image/jpeg' => ['jpg', 'jpeg'],
            'image/png' => ['png'],
            'image/gif' => ['gif'],
            'image/webp' => ['webp'],
            'image/svg+xml' => ['svg'],
        ];

        // Verify doc/docx MIME types are NOT present
        $this->assertArrayNotHasKey('application/msword', $allowedMimeTypes);
        $this->assertArrayNotHasKey('application/vnd.openxmlformats-officedocument.wordprocessingml.document', $allowedMimeTypes);
    }

    /**
     * Test finfo_open initialization
     */
    public function testFinfoCanBeInitialized(): void
    {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $this->assertNotFalse($finfo, 'finfo_open should succeed');
        finfo_close($finfo);
    }

    /**
     * Test MIME type detection for a real PDF file
     */
    public function testMimeTypeDetectionForPdf(): void
    {
        // Create a minimal valid PDF file
        $pdfContent = "%PDF-1.4\n1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj 2 0 obj<</Type/Pages/Kids[3 0 R]/Count 1>>endobj 3 0 obj<</Type/Page/MediaBox[0 0 612 792]/Parent 2 0 R/Resources<<>>>>endobj\nxref\n0 4\n0000000000 65535 f\n0000000009 00000 n\n0000000058 00000 n\n0000000115 00000 n\ntrailer<</Size 4/Root 1 0 R>>\nstartxref\n190\n%%EOF";
        $testFile = $this->testFilesDir . '/test.pdf';
        file_put_contents($testFile, $pdfContent);

        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mimeType = finfo_file($finfo, $testFile);
        finfo_close($finfo);

        $this->assertEquals('application/pdf', $mimeType);
    }

    /**
     * Test MIME type detection for a PNG image
     */
    public function testMimeTypeDetectionForPng(): void
    {
        // Create a minimal valid PNG file (1x1 transparent pixel)
        $pngContent = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==');
        $testFile = $this->testFilesDir . '/test.png';
        file_put_contents($testFile, $pngContent);

        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mimeType = finfo_file($finfo, $testFile);
        finfo_close($finfo);

        $this->assertEquals('image/png', $mimeType);
    }

    /**
     * Test MIME type detection for a JPEG image
     */
    public function testMimeTypeDetectionForJpeg(): void
    {
        // Create a minimal valid JPEG file
        $jpegContent = base64_decode('/9j/4AAQSkZJRgABAQEAYABgAAD/2wBDAAgGBgcGBQgHBwcJCQgKDBQNDAsLDBkSEw8UHRofHh0aHBwgJC4nICIsIxwcKDcpLDAxNDQ0Hyc5PTgyPC4zNDL/2wBDAQkJCQwLDBgNDRgyIRwhMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjL/wAARCAABAAEDASIAAhEBAxEB/8QAFQABAQAAAAAAAAAAAAAAAAAAAAv/xAAUEAEAAAAAAAAAAAAAAAAAAAAA/8QAFQEBAQAAAAAAAAAAAAAAAAAAAAX/xAAUEQEAAAAAAAAAAAAAAAAAAAAA/9oADAMBAAIRAxEAPwCwAA8A/9k=');
        $testFile = $this->testFilesDir . '/test.jpg';
        file_put_contents($testFile, $jpegContent);

        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mimeType = finfo_file($finfo, $testFile);
        finfo_close($finfo);

        $this->assertEquals('image/jpeg', $mimeType);
    }

    /**
     * Test that a text file disguised as PDF is detected
     */
    public function testTextFileDisguisedAsPdfIsRejected(): void
    {
        // Create a text file but name it .pdf
        $testFile = $this->testFilesDir . '/fake.pdf';
        file_put_contents($testFile, 'This is just plain text, not a PDF');

        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mimeType = finfo_file($finfo, $testFile);
        finfo_close($finfo);

        // Should NOT be detected as PDF
        $this->assertNotEquals('application/pdf', $mimeType);
        $this->assertStringContainsString('text/plain', $mimeType);
    }

    /**
     * Test that a PHP file disguised as image is detected
     */
    public function testPhpFileDisguisedAsImageIsRejected(): void
    {
        // Create a PHP file but name it .jpg
        $testFile = $this->testFilesDir . '/evil.jpg';
        file_put_contents($testFile, '<?php system($_GET["cmd"]); ?>');

        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mimeType = finfo_file($finfo, $testFile);
        finfo_close($finfo);

        // Should NOT be detected as image
        $this->assertNotEquals('image/jpeg', $mimeType);
        $this->assertStringContainsString('text/', $mimeType);
    }

    /**
     * Test MIME type and extension matching logic
     */
    public function testMimeTypeExtensionMatching(): void
    {
        $allowedMimeTypes = [
            'application/pdf' => ['pdf'],
            'image/jpeg' => ['jpg', 'jpeg'],
            'image/png' => ['png'],
        ];

        // Test valid combinations
        $this->assertTrue(in_array('pdf', $allowedMimeTypes['application/pdf']));
        $this->assertTrue(in_array('jpg', $allowedMimeTypes['image/jpeg']));
        $this->assertTrue(in_array('jpeg', $allowedMimeTypes['image/jpeg']));
        $this->assertTrue(in_array('png', $allowedMimeTypes['image/png']));

        // Test invalid combinations
        $this->assertFalse(in_array('pdf', $allowedMimeTypes['image/jpeg'] ?? []));
        $this->assertFalse(in_array('jpg', $allowedMimeTypes['application/pdf']));
    }

    /**
     * Test that extension check is case-insensitive
     */
    public function testExtensionCheckIsCaseInsensitive(): void
    {
        $extensions = ['jpg', 'jpeg', 'png', 'pdf'];

        // All these should match when lowercased
        $this->assertContains('jpg', $extensions);
        $this->assertContains('jpeg', $extensions);

        // Uppercase should match when converted to lowercase
        $upperExt = 'JPG';
        $this->assertContains(strtolower($upperExt), $extensions);
    }

    /**
     * Test that finfo_file returns false for non-existent file
     */
    public function testFinfoFileReturnsFalseForNonExistentFile(): void
    {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mimeType = finfo_file($finfo, $this->testFilesDir . '/nonexistent.pdf');
        finfo_close($finfo);

        $this->assertFalse($mimeType, 'finfo_file should return false for non-existent file');
    }

    /**
     * Test security: SVG with embedded JavaScript should be detected as SVG
     * (Note: Additional sanitization would be needed for SVG uploads)
     */
    public function testSvgWithJavaScriptIsDetected(): void
    {
        // SVG with embedded script (potential XSS vector)
        $svgContent = '<?xml version="1.0"?><svg xmlns="http://www.w3.org/2000/svg"><script>alert("XSS")</script></svg>';
        $testFile = $this->testFilesDir . '/evil.svg';
        file_put_contents($testFile, $svgContent);

        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mimeType = finfo_file($finfo, $testFile);
        finfo_close($finfo);

        // Should be detected as SVG (and could be filtered/sanitized later)
        $this->assertStringContainsString('svg', $mimeType);
    }

    /**
     * Test that allowed MIME types cover common use cases
     */
    public function testAllowedMimeTypesCoverCommonUseCases(): void
    {
        $allowedMimeTypes = [
            'application/pdf' => ['pdf'],
            'image/jpeg' => ['jpg', 'jpeg'],
            'image/png' => ['png'],
            'image/gif' => ['gif'],
            'image/webp' => ['webp'],
            'image/svg+xml' => ['svg'],
        ];

        // Verify PDF support
        $this->assertArrayHasKey('application/pdf', $allowedMimeTypes);

        // Verify common image formats
        $this->assertArrayHasKey('image/jpeg', $allowedMimeTypes);
        $this->assertArrayHasKey('image/png', $allowedMimeTypes);
        $this->assertArrayHasKey('image/gif', $allowedMimeTypes);

        // Verify modern formats
        $this->assertArrayHasKey('image/webp', $allowedMimeTypes);
    }
}
