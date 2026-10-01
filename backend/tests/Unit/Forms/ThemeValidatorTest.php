<?php
declare(strict_types=1);

namespace Tests\Unit\Forms;

use App\Forms\ThemeValidator;
use PHPUnit\Framework\TestCase;

class ThemeValidatorTest extends TestCase
{
    public function testRealThemeIsValid(): void
    {
        $json = (string)file_get_contents(__DIR__ . '/../../../../frontend/surveys/survey_theme.json');
        $out  = (new ThemeValidator())->validate($json);
        $this->assertSame([], $out['result']->errors());
        $this->assertSame([], $out['result']->warnings());
    }

    public function testEmptyObjectIsAValidTheme(): void
    {
        $this->assertTrue((new ThemeValidator())->validate('{}')['result']->isValid());
    }

    public function testBrokenOrWrongShapeIsRejected(): void
    {
        $v = new ThemeValidator();
        $this->assertFalse($v->validate('{')['result']->isValid());
        $this->assertFalse($v->validate('[1,2]')['result']->isValid());
        $this->assertFalse($v->validate('"text"')['result']->isValid());
        $this->assertFalse($v->validate(str_repeat(' ', ThemeValidator::MAX_BYTES + 1))['result']->isValid());
    }

    public function testMarkupAndDangerousUrlsAreRejected(): void
    {
        $v = new ThemeValidator();
        $this->assertFalse($v->validate('{"cssVariables":{"--a":"</style><script>x</script>"}}')['result']->isValid());
        $this->assertFalse($v->validate('{"backgroundImage":"javascript:alert(1)"}')['result']->isValid());
        $this->assertFalse($v->validate('{"a":{"b":["<img src=x>"]}}')['result']->isValid());
    }

    public function testExternalUrlIsAWarning(): void
    {
        $out = (new ThemeValidator())->validate('{"cssVariables":{"--bg":"url(https://cdn.example/bg.png)"}}');
        $this->assertTrue($out['result']->isValid());
        $this->assertCount(1, $out['result']->warnings());
    }
}
