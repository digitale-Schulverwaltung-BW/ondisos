<?php
declare(strict_types=1);

namespace Tests\Unit\Forms;

use App\Forms\SurveyValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class SurveyValidatorTest extends TestCase
{
    private SurveyValidator $validator;

    protected function setUp(): void
    {
        $this->validator = new SurveyValidator();
    }

    /** @param array<string,mixed> $survey */
    private function validate(array $survey): \App\Forms\ValidationResult
    {
        return $this->validator->validate(json_encode($survey, JSON_THROW_ON_ERROR))['result'];
    }

    /** @return array<string,mixed> */
    private function survey(array ...$elements): array
    {
        return ['pages' => [['name' => 'page1', 'elements' => $elements]]];
    }

    public function testRealSurveysOfTheProjectAreValid(): void
    {
        $dir   = __DIR__ . '/../../../../frontend/surveys';
        $files = glob($dir . '/*.json') ?: [];
        $this->assertNotEmpty($files, 'no survey files found at ' . $dir);

        foreach ($files as $file) {
            if (basename($file) === 'survey_theme.json') {
                continue; // a theme, not a survey
            }
            $result = $this->validator->validate((string)file_get_contents($file))['result'];
            $this->assertSame([], $result->errors(), basename($file) . ' must pass without errors');
        }
    }

    public function testMinimalSurveyIsValid(): void
    {
        $result = $this->validate($this->survey(['type' => 'text', 'name' => 'Name']));
        $this->assertTrue($result->isValid());
        $this->assertSame([], $result->warnings());
    }

    public function testTopLevelElementsWithoutPagesAreAccepted(): void
    {
        $result = $this->validate(['elements' => [['type' => 'text', 'name' => 'a']]]);
        $this->assertTrue($result->isValid());
    }

    public function testInvalidJsonIsRejected(): void
    {
        $out = $this->validator->validate('{"pages": [');
        $this->assertFalse($out['result']->isValid());
        $this->assertNull($out['survey']);
    }

    public function testListInsteadOfObjectIsRejected(): void
    {
        $out = $this->validator->validate('[1,2,3]');
        $this->assertFalse($out['result']->isValid());
    }

    public function testSurveyWithoutQuestionsIsRejected(): void
    {
        $this->assertFalse($this->validate(['pages' => []])->isValid());
        $this->assertFalse($this->validate(['title' => 'x'])->isValid());
    }

    public function testTooLargeSurveyIsRejectedBeforeParsing(): void
    {
        $out = $this->validator->validate(str_repeat('a', SurveyValidator::MAX_BYTES + 1));
        $this->assertFalse($out['result']->isValid());
        $this->assertStringContainsString('zu groß', $out['result']->errors()[0]['message']);
    }

    public function testQuestionWithoutNameIsRejected(): void
    {
        $result = $this->validate($this->survey(['type' => 'text']));
        $this->assertTrue($result->hasErrorAt('pages[0].elements[0].name'));
    }

    public function testElementWithoutTypeIsRejected(): void
    {
        $result = $this->validate($this->survey(['name' => 'x']));
        $this->assertTrue($result->hasErrorAt('pages[0].elements[0].type'));
    }

    public function testDuplicateFieldNamesAreRejected(): void
    {
        $result = $this->validate($this->survey(
            ['type' => 'text', 'name' => 'Vorname'],
            ['type' => 'text', 'name' => 'Vorname']
        ));
        $this->assertTrue($result->hasErrorAt('pages[0].elements[1].name'));
    }

    public function testDuplicateNamesAcrossPanelsAreRejected(): void
    {
        $result = $this->validate($this->survey(
            ['type' => 'text', 'name' => 'a'],
            ['type' => 'panel', 'name' => 'p', 'elements' => [['type' => 'text', 'name' => 'a']]]
        ));
        $this->assertTrue($result->hasErrorAt('pages[0].elements[1].elements[0].name'));
    }

    public function testDynamicPanelTemplateHasItsOwnNameScope(): void
    {
        $result = $this->validate($this->survey(
            ['type' => 'text', 'name' => 'a'],
            ['type' => 'paneldynamic', 'name' => 'kinder', 'templateElements' => [['type' => 'text', 'name' => 'a']]]
        ));
        $this->assertTrue($result->isValid());
    }

    public function testHtmlElementsNeedNoUniqueName(): void
    {
        $result = $this->validate($this->survey(
            ['type' => 'html', 'html' => '<p>eins</p>'],
            ['type' => 'html', 'html' => '<p>zwei</p>'],
            ['type' => 'text', 'name' => 'a']
        ));
        $this->assertTrue($result->isValid());
    }

    public function testReservedFieldNameIsRejected(): void
    {
        $result = $this->validate($this->survey(['type' => 'text', 'name' => '_fieldTypes']));
        $this->assertTrue($result->hasErrorAt('pages[0].elements[0].name'));
    }

    /** @return array<string,array{0:string}> */
    public static function dangerousHtml(): array
    {
        return [
            'script tag'          => ['<script>alert(1)</script>'],
            'close script'        => ['</script><script>alert(1)</script>'],
            'img onerror'         => ['<img src=x onerror=alert(1)>'],
            'inline handler'      => ['<a href="https://x.de" onclick="alert(1)">x</a>'],
            'javascript href'     => ['<a href="javascript:alert(1)">x</a>'],
            'obfuscated href'     => ['<a href="java&#x09;script:alert(1)">x</a>'],
            'iframe'              => ['<iframe src="https://evil.example"></iframe>'],
            'style attribute'     => ['<p style="background:url(x)">x</p>'],
            'style tag'           => ['<style>body{display:none}</style>'],
            'svg'                 => ['<svg onload=alert(1)></svg>'],
            'form'                => ['<form action="https://evil.example"><input></form>'],
            'data href'           => ['<a href="data:text/html;base64,AAAA">x</a>'],
        ];
    }

    #[DataProvider('dangerousHtml')]
    public function testDangerousHtmlInHtmlElementIsRejected(string $html): void
    {
        $result = $this->validate($this->survey(['type' => 'html', 'name' => 'h', 'html' => $html]));
        $this->assertFalse($result->isValid(), $html);
    }

    public function testDangerousHtmlInOtherTextsIsRejected(): void
    {
        $survey = $this->survey(['type' => 'text', 'name' => 'a', 'description' => '<img src=x onerror=alert(1)>']);
        $survey['completedHtml'] = '<script>alert(1)</script>';
        $result = $this->validate($survey);
        $this->assertTrue($result->hasErrorAt('pages[0].elements[0].description'));
        $this->assertTrue($result->hasErrorAt('completedHtml'));
    }

    public function testAllowedHtmlPasses(): void
    {
        $html = '<h3>Titel</h3><p>Text mit <b>fett</b>, <i>kursiv</i><br />und '
              . '<a href="https://www.example.de/datenschutz/" target="_blank" rel="noopener">Link</a> '
              . 'oder <a href="mailto:a@b.de">Mail</a>.</p><ul><li>eins</li></ul>';
        $result = $this->validate($this->survey(['type' => 'html', 'name' => 'h', 'html' => $html]));
        $this->assertSame([], $result->errors());
    }

    public function testJavascriptUrlInNavigateToUrlIsRejected(): void
    {
        $survey = $this->survey(['type' => 'text', 'name' => 'a']);
        $survey['navigateToUrl'] = 'javascript:alert(1)';
        $this->assertTrue($this->validate($survey)->hasErrorAt('navigateToUrl'));
    }

    public function testLessThanInExpressionsIsNotTreatedAsHtml(): void
    {
        $result = $this->validate($this->survey([
            'type' => 'text', 'name' => 'a', 'visibleIf' => '{alter} <18 or {x} <y',
        ]));
        $this->assertSame([], $result->errors());
    }

    public function testComparisonInPlainTextIsNotTreatedAsHtml(): void
    {
        $result = $this->validate($this->survey(['type' => 'text', 'name' => 'a', 'title' => 'Wert < 5 und > 3']));
        $this->assertSame([], $result->errors());
    }

    public function testUnknownExpressionFunctionIsAWarningNotAnError(): void
    {
        $result = $this->validate($this->survey([
            'type' => 'text', 'name' => 'a', 'visibleIf' => 'evil({b}) = 1 and iif({c} = 1, 1, 2) = 1',
        ]));
        $this->assertTrue($result->isValid());
        $this->assertCount(1, $result->warnings());
        $this->assertStringContainsString('evil()', $result->warnings()[0]['message']);
    }

    public function testFunctionNamesInQuotedLiteralsAreIgnored(): void
    {
        $result = $this->validate($this->survey([
            'type' => 'text', 'name' => 'a', 'visibleIf' => "{x} = 'foo(bar)'",
        ]));
        $this->assertSame([], $result->warnings());
    }

    public function testChoicesByUrlIsAWarning(): void
    {
        $result = $this->validate($this->survey([
            'type' => 'dropdown', 'name' => 'a', 'choicesByUrl' => ['url' => 'https://third.example/list.json'],
        ]));
        $this->assertTrue($result->isValid());
        $this->assertNotEmpty($result->warnings());
    }
}
