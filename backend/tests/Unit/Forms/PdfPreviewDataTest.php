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
}
