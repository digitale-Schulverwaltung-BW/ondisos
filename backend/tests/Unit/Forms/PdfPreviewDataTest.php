<?php
declare(strict_types=1);

namespace Tests\Unit\Forms;

use App\Forms\PdfPreviewData;
use PHPUnit\Framework\TestCase;

class PdfPreviewDataTest extends TestCase
{
    public function testSampleAnswersByQuestionType(): void
    {
        $survey = ['pages' => [['elements' => [
            ['type' => 'text', 'name' => 'Vorname'],
            ['type' => 'text', 'name' => 'Geburtsdatum', 'inputType' => 'date'],
            ['type' => 'text', 'name' => 'Alter', 'inputType' => 'number'],
            ['type' => 'html', 'name' => 'hinweis', 'html' => '<p>x</p>'],
            ['type' => 'panel', 'name' => 'p', 'elements' => [
                ['type' => 'radiogroup', 'name' => 'Klasse', 'choices' => ['5a', '5b']],
                ['type' => 'checkbox', 'name' => 'Hobbys', 'choices' => [['value' => 'x', 'text' => 'Fußball']]],
                ['type' => 'boolean', 'name' => 'Ja_Nein'],
            ]],
        ]]]];

        $data = PdfPreviewData::fromSurvey($survey);

        $this->assertSame('Vorname', $data['Vorname']);
        $this->assertSame('2000-01-01', $data['Geburtsdatum']);
        $this->assertSame('1', $data['Alter']);
        $this->assertSame('5a', $data['Klasse']);
        $this->assertSame(['Fußball'], $data['Hobbys']);
        $this->assertTrue($data['Ja_Nein']);
        $this->assertArrayNotHasKey('hinweis', $data);
        $this->assertSame(['Vorname', 'Geburtsdatum', 'Alter', 'Klasse', 'Hobbys', 'Ja_Nein'], array_keys($data['_fieldTypes']));
    }

    public function testChoiceWithoutChoicesFallsBackToFieldName(): void
    {
        $data = PdfPreviewData::fromSurvey(['elements' => [['type' => 'dropdown', 'name' => 'Wahl', 'choicesByUrl' => ['url' => 'x']]]]);
        $this->assertSame('Wahl', $data['Wahl']);
    }

    public function testConfigIgnoresWrongTypesAndDropsLogoPath(): void
    {
        $config = PdfPreviewData::config([
            'title' => ['x'], 'header_title' => 'Kopf', 'include_fields' => ['a', 5], 'exclude_fields' => 'nope',
            'pre_sections' => [['title' => 'T', 'content' => 'C'], 'junk'], 'logo' => '/etc/passwd',
        ]);

        $this->assertArrayNotHasKey('title', $config);
        $this->assertSame('Kopf', $config['header_title']);
        $this->assertSame(['a'], $config['include_fields']);
        $this->assertSame([], $config['exclude_fields']);
        $this->assertSame([['title' => 'T', 'content' => 'C']], $config['pre_sections']);
        $this->assertArrayNotHasKey('logo', $config);
    }

    public function testConfigDropsEmptySections(): void
    {
        $config = PdfPreviewData::config([
            'pre_sections' => [['title' => '', 'content' => ''], ['title' => '  ', 'content' => "\n"], ['title' => 'Hinweis', 'content' => '']],
            'post_sections' => [['title' => '', 'content' => '']],
        ]);
        $this->assertSame([['title' => 'Hinweis', 'content' => '']], $config['pre_sections']);
        $this->assertSame([], $config['post_sections']);
    }

    public function testFieldTypesCarryQuestionAndPageTitles(): void
    {
        $survey = ['pages' => [
            ['name' => 'schueler', 'title' => 'Angaben Schüler', 'elements' => [
                ['type' => 'text', 'name' => 'Vorname', 'title' => 'Vorname des Kindes'],
                ['type' => 'panel', 'name' => 'p', 'title' => 'Wird nicht verwendet', 'elements' => [['type' => 'text', 'name' => 'Ort']]],
            ]],
            ['title' => ['default' => 'Schulbildung'], 'elements' => [['type' => 'text', 'name' => 'Abschluss']]],
        ]];

        $types = PdfPreviewData::fromSurvey($survey)['_fieldTypes'];

        $this->assertSame(['type' => 'text', 'title' => 'Vorname des Kindes', 'page' => 'schueler', 'pageTitle' => 'Angaben Schüler'], $types['Vorname']);
        $this->assertSame('Ort', $types['Ort']['title'], 'without title the field name');
        $this->assertSame('Angaben Schüler', $types['Ort']['pageTitle'], 'panels do not make headings');
        $this->assertSame(['type' => 'text', 'title' => 'Abschluss', 'page' => 'page2', 'pageTitle' => 'Schulbildung'], $types['Abschluss']);
    }

    public function testSurveyWithoutPagesHasNoPageInformation(): void
    {
        $types = PdfPreviewData::fromSurvey(['elements' => [['type' => 'text', 'name' => 'Name']]])['_fieldTypes'];
        $this->assertSame(['type' => 'text', 'title' => 'Name'], $types['Name']);
    }

    public function testConfigTakesSectionHeadingsFromPostedOrStoredValue(): void
    {
        $this->assertFalse(PdfPreviewData::config(['section_headings' => '0'])['section_headings']);
        $this->assertTrue(PdfPreviewData::config(['section_headings' => true])['section_headings']);
        $this->assertArrayNotHasKey('section_headings', PdfPreviewData::config([]));
    }

    public function testQuestionsInChoiceOptionsFollowTheirQuestionOnTheSamePage(): void
    {
        $survey = ['pages' => [
            ['name' => 'kurs', 'title' => 'Kurs', 'elements' => [['type' => 'radiogroup', 'name' => 'Form', 'choices' => [
            ['value' => 'VZ', 'elements' => [['type' => 'radiogroup', 'name' => 'Schwerpunkt', 'choices' => [
                ['value' => 'a', 'elements' => [['type' => 'text', 'name' => 'Beginn', 'isRequired' => true]]],
            ]]]],
            'TZ',
        ]]]],
            ['name' => 'ende', 'title' => 'Absenden', 'elements' => [['type' => 'comment', 'name' => 'Anmerkungen']]],
        ]];

        $types = PdfPreviewData::fromSurvey($survey)['_fieldTypes'];

        $this->assertSame(['Form', 'Schwerpunkt', 'Beginn', 'Anmerkungen'], array_keys($types));
        $this->assertSame('kurs', $types['Beginn']['page']);
    }
}
