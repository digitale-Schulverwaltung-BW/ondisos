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
}
