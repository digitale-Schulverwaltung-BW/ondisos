<?php
declare(strict_types=1);

namespace Tests\Unit\Forms;

use App\Forms\SurveyFieldExtractor;
use PHPUnit\Framework\TestCase;

class SurveyFieldExtractorTest extends TestCase
{
    public function testFieldsInFormOrderIncludingPanelsAndWithoutDisplayElements(): void
    {
        $survey = ['pages' => [
            ['elements' => [
                ['type' => 'html', 'name' => 'intro', 'html' => '<p>x</p>'],
                ['type' => 'text', 'name' => 'Vorname', 'title' => 'Vorname des Kindes'],
                ['type' => 'panel', 'name' => 'p', 'elements' => [
                    ['type' => 'radiogroup', 'name' => 'Geschlecht'],
                ]],
            ]],
            ['elements' => [
                ['type' => 'image', 'name' => 'logo'],
                ['type' => 'paneldynamic', 'name' => 'kinder', 'templateElements' => [['type' => 'text', 'name' => 'inner']]],
            ]],
        ]];

        $fields = SurveyFieldExtractor::fields($survey);

        $this->assertSame(['Vorname', 'Geschlecht', 'kinder'], array_keys($fields));
        $this->assertSame('radiogroup', $fields['Geschlecht']['type']);
        $this->assertSame('Vorname des Kindes', $fields['Vorname']['title']);
        $this->assertNull($fields['Geschlecht']['title']);
    }

    public function testLocalizedTitleObjectGivesNullTitle(): void
    {
        $fields = SurveyFieldExtractor::fields(['elements' => [
            ['type' => 'text', 'name' => 'a', 'title' => ['default' => 'A', 'de' => 'Ä']],
        ]]);
        $this->assertNull($fields['a']['title']);
    }

    public function testEmptyAndMalformedSurveysGiveNoFields(): void
    {
        $this->assertSame([], SurveyFieldExtractor::fields([]));
        $this->assertSame([], SurveyFieldExtractor::fields(['pages' => 'x', 'elements' => 5]));
        $this->assertSame([], SurveyFieldExtractor::fields(['pages' => [['elements' => ['no-array', null]]]]));
    }

    public function testRealSurveyHasFields(): void
    {
        $survey = json_decode((string)file_get_contents(__DIR__ . '/../../../../frontend/surveys/bs.json'), true);
        $names  = SurveyFieldExtractor::fieldNames($survey);
        $this->assertNotEmpty($names);
        $this->assertSame($names, array_values(array_unique($names)));
    }

    public function testQuestionsInChoiceOptionsAreConditionalFieldsInFormOrder(): void
    {
        $survey = ['pages' => [['elements' => [
            ['type' => 'radiogroup', 'name' => 'Form', 'choices' => [
            ['value' => 'VZ', 'elements' => [['type' => 'radiogroup', 'name' => 'Schwerpunkt', 'choices' => [
                ['value' => 'a', 'elements' => [['type' => 'text', 'name' => 'Beginn', 'isRequired' => true]]],
            ]]]],
            'TZ',
        ]],
            ['type' => 'text', 'name' => 'Danach'],
        ]]]];

        $fields = SurveyFieldExtractor::fields($survey);

        $this->assertSame(['Form', 'Schwerpunkt', 'Beginn', 'Danach'], array_keys($fields));
        $this->assertFalse($fields['Form']['conditional']);
        $this->assertTrue($fields['Beginn']['conditional'], 'shown only while its option is selected');
        $this->assertTrue($fields['Beginn']['required']);
    }
}
