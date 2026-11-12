<?php
declare(strict_types=1);

namespace Tests\Unit\Forms;

use App\Forms\ThemeValidator;
use PHPUnit\Framework\TestCase;

/**
 * The built-in default theme (templates/theme/default.json, 3.2.0b AP0): valid and in the SurveyJS 3 format.
 */
class DefaultThemeTest extends TestCase
{
    private const PATH = __DIR__ . '/../../../templates/theme/default.json';

    public function testDefaultThemePassesTheValidatorWithoutWarnings(): void
    {
        $result = (new ThemeValidator())->validate((string)file_get_contents(self::PATH))['result'];

        $this->assertTrue($result->isValid(), json_encode($result->errors()));
        $this->assertSame([], $result->warnings());
    }

    public function testDefaultThemeUsesOnlySurveyJs3Variables(): void
    {
        $theme = json_decode((string)file_get_contents(self::PATH), true, 32, JSON_THROW_ON_ERROR);

        $this->assertNotEmpty($theme['cssVariables']);
        foreach (array_keys($theme['cssVariables']) as $name) {
            $this->assertStringStartsWith('--sjs2-', $name);
        }
        $this->assertSame('rgba(0, 163, 215, 1)', $theme['cssVariables']['--sjs2-color-project-brand-600']);
    }
}
