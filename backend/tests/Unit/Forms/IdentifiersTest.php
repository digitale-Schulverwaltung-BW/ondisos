<?php
declare(strict_types=1);

namespace Tests\Unit\Forms;

use App\Forms\Identifiers;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class IdentifiersTest extends TestCase
{
    /** @return array<string,array{0:string,1:bool}> */
    public static function formKeys(): array
    {
        return [
            'simple' => ['bs', true], 'underscore' => ['prefill_demo', true], 'dash' => ['a-b', true],
            'digit first' => ['5a', true], 'empty' => ['', false], 'upper' => ['BS', false],
            'slash' => ['a/b', false], 'dots' => ['..', false], 'dot' => ['a.b', false],
            'space' => ['a b', false], 'leading dash' => ['-a', false], 'too long' => [str_repeat('a', 101), false],
            'newline' => ["a\n", false],
        ];
    }

    #[DataProvider('formKeys')]
    public function testFormKeyValidation(string $key, bool $valid): void
    {
        $this->assertSame($valid, Identifiers::isValidFormKey($key));
    }

    /** @return array<string,array{0:string,1:bool}> */
    public static function resourceNames(): array
    {
        return [
            'survey' => ['bs.json', true], 'theme' => ['survey_theme.json', true], 'no extension' => ['bs', false],
            'wrong extension' => ['bs.php', false], 'traversal' => ['../bs.json', false], 'subdir' => ['a/b.json', false],
            'absolute' => ['/etc/passwd.json', false], 'double extension' => ['bs.json.php', false],
            'upper' => ['BS.json', false], 'hidden' => ['.bs.json', false], 'empty stem' => ['.json', false],
            'null byte' => ["bs.json\0", false], 'newline' => ["bs.json\n", false],
            'too long' => [str_repeat('a', 65) . '.json', false],
        ];
    }

    #[DataProvider('resourceNames')]
    public function testResourceNameValidation(string $name, bool $valid): void
    {
        $this->assertSame($valid, Identifiers::isValidResourceName($name));
    }
}
