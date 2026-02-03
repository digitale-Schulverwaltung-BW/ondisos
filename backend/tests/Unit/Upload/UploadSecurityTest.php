<?php
declare(strict_types=1);

namespace Tests\Unit\Upload;

use PHPUnit\Framework\TestCase;

/**
 * Tests for File Upload Security
 *
 * This test validates the filename sanitization logic used in upload.php
 * to prevent directory traversal, double extension attacks, and other injection attempts.
 *
 * NOTE: These tests validate the sanitization logic, not the actual file upload.
 */
class UploadSecurityTest extends TestCase
{
    /**
     * Test the filename sanitization logic from upload.php
     *
     * This simulates the logic: basename() + validation + forced extension
     */
    private function sanitizeFilename(string $originalName, string $validatedExtension, int $anmeldungId): string
    {
        // 1. Strip path components
        $originalName = basename($originalName);

        // 2. Get filename without extension
        $nameWithoutExt = pathinfo($originalName, PATHINFO_FILENAME);

        // 3. Validate (only alphanumeric, underscore, hyphen)
        if (!preg_match('/^[a-zA-Z0-9_-]+$/', $nameWithoutExt)) {
            throw new \RuntimeException('Invalid filename');
        }

        // 4. Force extension (prevents double extension)
        return $anmeldungId . '_' . $nameWithoutExt . '.' . $validatedExtension;
    }

    /**
     * Test that valid filenames are accepted
     */
    public function testSanitizeAcceptsValidFilenames(): void
    {
        $validFiles = [
            ['file.jpg', 'jpg', '123_file.jpg'],
            ['test-file.pdf', 'pdf', '123_test-file.pdf'],
            ['test_file.png', 'png', '123_test_file.png'],
            ['Test123.doc', 'doc', '123_Test123.doc'],
            ['a.jpg', 'jpg', '123_a.jpg'],
        ];

        foreach ($validFiles as [$input, $ext, $expected]) {
            $result = $this->sanitizeFilename($input, $ext, 123);
            $this->assertEquals($expected, $result, "Failed for input: $input");
        }
    }

    /**
     * Test that directory traversal attempts are blocked
     *
     * Note: Full paths like '../../etc/passwd' are handled by basename()
     * which returns 'passwd' (valid). We test direct attempts like '..' or '.'
     */
    public function testSanitizeBlocksDirectoryTraversal(): void
    {
        // Test attempts that would remain after basename()
        $traversalAttempts = [
            '..',       // Parent directory
            '.',        // Current directory
            '...',      // Multiple dots
        ];

        foreach ($traversalAttempts as $attempt) {
            try {
                $this->sanitizeFilename($attempt, 'txt', 123);
                $this->fail("Expected exception for traversal attempt: $attempt");
            } catch (\RuntimeException $e) {
                $this->assertStringContainsString('Invalid filename', $e->getMessage());
            }
        }
    }

    /**
     * Test that basename() strips path components
     */
    public function testBasenameStripsPathComponents(): void
    {
        // Even if slashes were allowed, basename() would strip them
        $input = 'some/path/to/file.jpg';
        $result = basename($input);

        $this->assertEquals('file.jpg', $result);
    }

    /**
     * Test that double extensions are prevented (evil.php.jpg)
     */
    public function testSanitizePreventsDoubleExtensions(): void
    {
        // Input: evil.php.jpg
        // pathinfo(FILENAME): evil.php
        // Validation: FAILS because of dot in middle
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Invalid filename');

        $this->sanitizeFilename('evil.php.jpg', 'jpg', 123);
    }

    /**
     * Test that extension is forced (prevents extension injection)
     */
    public function testSanitizeForcesExtension(): void
    {
        // Even if user uploads "test.jpg", we force the validated extension
        $result = $this->sanitizeFilename('test.jpg', 'png', 123);

        // Should be: 123_test.png (not .jpg!)
        $this->assertEquals('123_test.png', $result);
        $this->assertStringEndsWith('.png', $result);
    }

    /**
     * Test that dots in filename (except extension) are blocked
     */
    public function testSanitizeRejectsDotInFilename(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Invalid filename');

        // test.backup.jpg -> filename is "test.backup" (has dot)
        $this->sanitizeFilename('test.backup.jpg', 'jpg', 123);
    }

    /**
     * Test that special characters are blocked
     *
     * Note: Path separators (/ and \) are already handled by basename() which
     * strips them out. We test characters that would remain after basename().
     */
    public function testSanitizeRejectsSpecialCharacters(): void
    {
        $invalidNames = [
            'test@file.jpg',
            'test!file.jpg',
            'test$file.jpg',
            'test%file.jpg',
            'test&file.jpg',
            'test*file.jpg',
            'test(file).jpg',
            'test[file].jpg',
            'test{file}.jpg',
            'test|file.jpg',
            // Note: Slashes are already stripped by basename(), so we don't test them here
            // 'test\\file.jpg' -> basename() -> 'file.jpg' (valid)
            // 'test/file.jpg' -> basename() -> 'file.jpg' (valid)
            'test<file>.jpg',
            'test>file.jpg',
            'test,file.jpg',
            'test;file.jpg',
            'test:file.jpg',
            'test?file.jpg',
            'test file.jpg', // space
            "test'file.jpg", // single quote
            'test"file.jpg', // double quote
            'test`file.jpg', // backtick
        ];

        foreach ($invalidNames as $name) {
            try {
                $this->sanitizeFilename($name, 'jpg', 123);
                $this->fail("Expected exception for invalid filename: $name");
            } catch (\RuntimeException $e) {
                $this->assertStringContainsString('Invalid filename', $e->getMessage());
            }
        }
    }

    /**
     * Test that null bytes are blocked (prevents null byte injection)
     */
    public function testSanitizeRejectsNullByte(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Invalid filename');

        // test\0.php.jpg (null byte injection)
        $this->sanitizeFilename("test\0.php.jpg", 'jpg', 123);
    }

    /**
     * Test that unicode characters are blocked
     */
    public function testSanitizeRejectsUnicode(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Invalid filename');

        $this->sanitizeFilename('testö.jpg', 'jpg', 123);
    }

    /**
     * Test that empty filename is blocked
     */
    public function testSanitizeRejectsEmptyFilename(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Invalid filename');

        $this->sanitizeFilename('.jpg', 'jpg', 123);
    }

    /**
     * Test that only extension (no name) is blocked
     */
    public function testSanitizeRejectsOnlyExtension(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Invalid filename');

        $this->sanitizeFilename('.htaccess', 'txt', 123);
    }

    /**
     * Test anmeldungId is prepended correctly
     */
    public function testSanitizePrependsAnmeldungId(): void
    {
        $result = $this->sanitizeFilename('test.jpg', 'jpg', 456);

        $this->assertEquals('456_test.jpg', $result);
        $this->assertStringStartsWith('456_', $result);
    }

    /**
     * Test pathinfo() behavior with various inputs
     */
    public function testPathinfoExtractsFilenameCorrectly(): void
    {
        $tests = [
            ['test.jpg', 'test', 'jpg'],
            ['test.backup.jpg', 'test.backup', 'jpg'],
            ['test', 'test', ''],
            ['.htaccess', '', 'htaccess'],
            ['test.', 'test', ''],
        ];

        foreach ($tests as [$input, $expectedFilename, $expectedExt]) {
            $filename = pathinfo($input, PATHINFO_FILENAME);
            $ext = pathinfo($input, PATHINFO_EXTENSION);

            $this->assertEquals($expectedFilename, $filename, "Filename mismatch for: $input");
            $this->assertEquals($expectedExt, $ext, "Extension mismatch for: $input");
        }
    }

    /**
     * Test that the regex pattern is strict enough
     */
    public function testRegexPatternIsStrict(): void
    {
        $pattern = '/^[a-zA-Z0-9_-]+$/';

        // Valid
        $this->assertEquals(1, preg_match($pattern, 'test'));
        $this->assertEquals(1, preg_match($pattern, 'test123'));
        $this->assertEquals(1, preg_match($pattern, 'test_file'));
        $this->assertEquals(1, preg_match($pattern, 'test-file'));

        // Invalid
        $this->assertEquals(0, preg_match($pattern, 'test.file')); // dot
        $this->assertEquals(0, preg_match($pattern, 'test file')); // space
        $this->assertEquals(0, preg_match($pattern, 'test/file')); // slash
        $this->assertEquals(0, preg_match($pattern, '../test')); // dots
        $this->assertEquals(0, preg_match($pattern, '')); // empty
    }
}
