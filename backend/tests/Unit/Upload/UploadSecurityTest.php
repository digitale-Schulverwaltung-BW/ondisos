<?php
declare(strict_types=1);

namespace Tests\Unit\Upload;

use App\Utils\FilenameSanitizer;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the filename handling of uploads (App\Utils\FilenameSanitizer, used by upload.php).
 *
 * Filenames are SANITIZED, not rejected: path components, dots and special characters
 * become underscores, umlauts are transliterated, and upload.php forces the extension
 * that was validated from the file content. The actual file type checks live in
 * MimeTypeValidationTest / AnmeldungValidator.
 */
class UploadSecurityTest extends TestCase
{
    private const SAFE = '/^[a-zA-Z0-9_-]+$/';

    /**
     * Mirrors upload.php: basename(), stem, sanitize, forced validated extension.
     */
    private function storedName(int $anmeldungId, string $originalName, string $validatedExtension): string
    {
        $stem = pathinfo(basename($originalName), PATHINFO_FILENAME);

        return $anmeldungId . '_' . FilenameSanitizer::sanitizeStem($stem) . '.' . $validatedExtension;
    }

    public function testValidNamesAreKept(): void
    {
        foreach (['file', 'test-file', 'test_file', 'Test123', 'a'] as $stem) {
            $this->assertSame($stem, FilenameSanitizer::sanitizeStem($stem));
        }
    }

    public function testUmlautsAreTransliterated(): void
    {
        $this->assertSame('Zeugnis_Uebersicht_2026', FilenameSanitizer::sanitizeStem('Zeugnis Übersicht 2026'));
        $this->assertSame('aeoeuess_AeOeUe', FilenameSanitizer::sanitizeStem('äöüß ÄÖÜ'));
    }

    public function testDotsAndDoubleExtensionsAreNeutralised(): void
    {
        $this->assertSame('evil_php', FilenameSanitizer::sanitizeStem('evil.php'));
        // The extension is forced from the validated content type, not taken from the name
        $this->assertSame('5_evil_php.jpg', $this->storedName(5, 'evil.php.jpg', 'jpg'));
        $this->assertSame('5_test_backup.pdf', $this->storedName(5, 'test.backup.pdf', 'pdf'));
    }

    public function testDirectoryTraversalCannotEscape(): void
    {
        $this->assertSame('7_passwd.pdf', $this->storedName(7, '../../etc/passwd', 'pdf'));
        $this->assertSame('etc_passwd', FilenameSanitizer::sanitizeStem('../../etc/passwd'));
        $this->assertSame('7_upload.pdf', $this->storedName(7, '..', 'pdf'));
    }

    public function testSpecialCharactersNullBytesAndUnicodeBecomeUnderscores(): void
    {
        $this->assertSame('test_rm_-rf', FilenameSanitizer::sanitizeStem('test;rm -rf'));
        $this->assertSame('a_b', FilenameSanitizer::sanitizeStem("a\0b"));
        $this->assertSame('a_b', FilenameSanitizer::sanitizeStem('a   |&   b'));
        $this->assertSame('caf', FilenameSanitizer::sanitizeStem('café'));
    }

    public function testEmptyAndHiddenNamesFallBackToUpload(): void
    {
        $this->assertSame('upload', FilenameSanitizer::sanitizeStem(''));
        $this->assertSame('upload', FilenameSanitizer::sanitizeStem('***'));
        // ".htaccess" has an empty stem in pathinfo(): it can never be stored as a dotfile
        $this->assertSame('3_upload.txt', $this->storedName(3, '.htaccess', 'txt'));
    }

    public function testStoredNameIsPrefixedWithAnmeldungId(): void
    {
        $this->assertStringStartsWith('123_', $this->storedName(123, 'x.pdf', 'pdf'));
        $this->assertSame('123_x.pdf', $this->storedName(123, 'x.pdf', 'pdf'));
    }

    /**
     * @return array<string,array{string}>
     */
    public static function hostileStems(): array
    {
        return [
            'traversal'      => ['../../../etc/passwd'],
            'windows path'   => ['..\\..\\windows\\system32'],
            'null byte'      => ["evil.php\0.jpg"],
            'shell'          => ['$(rm -rf /);`id`'],
            'html'           => ['<script>alert(1)</script>'],
            'unicode'        => ['名前'],
            'newline'        => ["a\nb\r\nc"],
            'only dots'      => ['....'],
            'very long'      => [str_repeat('a b.', 200)],
        ];
    }

    /**
     * @dataProvider hostileStems
     */
    public function testAnyInputYieldsOnlySafeCharacters(string $stem): void
    {
        $result = FilenameSanitizer::sanitizeStem($stem);

        $this->assertMatchesRegularExpression(self::SAFE, $result);
        $this->assertStringNotContainsString('..', $result);
    }

    public function testDiskNameKeepsGivenExtensionAndSanitizesStem(): void
    {
        $this->assertSame('9_Uebersicht.pdf', FilenameSanitizer::diskName(9, 'Übersicht.pdf'));
    }
}
