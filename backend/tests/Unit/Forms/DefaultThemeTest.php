<?php
declare(strict_types=1);

namespace Tests\Unit\Forms;

use App\Forms\ThemeValidator;
use PHPUnit\Framework\TestCase;

/**
 * The built-in default theme (templates/theme/default.json, 3.2.0b AP0): valid and in the SurveyJS 3 format. The frontend's
 * theme file (frontend/surveys/survey_theme.json, the fallback while a school has no theme in the database) is the same file
 * (PLAN-3.2.0, "frontend/surveys/ ablösen"); the brand colour meets WCAG AA against white (the theme editor's hint).
 */
class DefaultThemeTest extends TestCase
{
    private const PATH = __DIR__ . '/../../../templates/theme/default.json';
    private const FRONTEND = __DIR__ . '/../../../../frontend/surveys/survey_theme.json';

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
        $this->assertSame('rgba(0, 115, 165, 1)', $theme['cssVariables']['--sjs2-color-project-brand-600']);
    }

    public function testTheFrontendThemeFileIsTheDefaultByteForByte(): void
    {
        $this->assertSame((string)file_get_contents(self::PATH), (string)file_get_contents(self::FRONTEND),
            'frontend/surveys/survey_theme.json differs from backend/templates/theme/default.json: copy the default over it');
    }

    public function testTheBrandColourMeetsWcagAaAgainstWhite(): void
    {
        $theme = json_decode((string)file_get_contents(self::PATH), true, 32, JSON_THROW_ON_ERROR);
        preg_match('/^rgba?\((\d+),\s*(\d+),\s*(\d+)/', $theme['cssVariables']['--sjs2-color-project-brand-600'], $m);
        $this->assertCount(4, $m, 'brand colour as rgb()/rgba()');
        $channel = static function (string $c): float {
            $c = (int)$c / 255;
            return $c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
        };
        $luminance = 0.2126 * $channel($m[1]) + 0.7152 * $channel($m[2]) + 0.0722 * $channel($m[3]);
        $this->assertGreaterThanOrEqual(4.5, 1.05 / ($luminance + 0.05));
    }
}
