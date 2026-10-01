<?php
declare(strict_types=1);

namespace Tests\Unit\Forms;

use App\Forms\SurveyFieldExtractor;
use App\Forms\SurveyLinter;
use PHPUnit\Framework\TestCase;

class SurveyLinterTest extends TestCase
{
    /** @return array<string,mixed> */
    private function survey(string ...$names): array
    {
        return [
            'calculatedValues' => [['name' => 'Alter', 'expression' => 'age({Geburtstag})']],
            'pages' => [['elements' => array_map(fn ($n) => ['type' => 'text', 'name' => $n], $names)]],
        ];
    }

    public function testConsistentConfigHasNoWarnings(): void
    {
        $result = (new SurveyLinter())->lint([
            'db' => true,
            'prefill_fields' => ['Firma'],
            'pdf' => ['exclude_fields' => ['consent'], 'include_fields' => 'all'],
            'email' => ['intro_template' => '{Vorname} ist {Alter} Jahre alt'],
        ], $this->survey('Vorname', 'Firma', 'consent', 'email'));

        $this->assertSame([], $result->warnings());
        $this->assertSame([], $result->errors());
    }

    public function testUnknownNamesAreWarningsNotErrors(): void
    {
        $result = (new SurveyLinter())->lint([
            'db' => false,
            'notify_email' => ['sekretariat@example.de'],
            'prefill_fields' => ['Firma', 'Tippfehler'],
            'pdf' => ['exclude_fields' => ['gibtsnicht'], 'include_fields' => ['Vorname', 'auch_nicht']],
            'email' => ['intro_template' => '{Vorname} {Unbekannt}'],
        ], $this->survey('Vorname', 'Firma'));

        $this->assertTrue($result->isValid());
        $messages = implode(' | ', array_column($result->warnings(), 'message'));
        foreach (['Tippfehler', 'gibtsnicht', 'auch_nicht', 'Unbekannt'] as $needle) {
            $this->assertStringContainsString($needle, $messages);
        }
        $this->assertCount(4, $result->warnings());
    }

    public function testMissingEmailFieldWarnsOnlyWhenSubmissionsAreStored(): void
    {
        $survey = $this->survey('Vorname');
        $linter = new SurveyLinter();

        $this->assertCount(1, $linter->lint(['db' => true], $survey)->warnings());
        $this->assertCount(1, $linter->lint([], $survey)->warnings(), 'db defaults to true');
        $this->assertSame([], $linter->lint(['db' => false, 'notify_email' => 'sekretariat@example.de'], $survey)->warnings());
    }

    /** @return array<string,array{0:array<string,mixed>,1:bool}> */
    public static function discardCases(): array
    {
        return [
            'stores in db'                => [['db' => true], false],
            'db default is true'          => [[], false],
            'db off, no recipient'        => [['db' => false], true],
            'db off, empty recipient'     => [['db' => false, 'notify_email' => ''], true],
            'db off, empty list'          => [['db' => false, 'notify_email' => []], true],
            'db off, invalid recipient'   => [['db' => false, 'notify_email' => 'kein-mail'], true],
            'db off, valid string'        => [['db' => false, 'notify_email' => 'a@b.de'], false],
            'db off, valid comma string'  => [['db' => false, 'notify_email' => 'x, a@b.de'], false],
            'db off, valid list'          => [['db' => false, 'notify_email' => ['a@b.de']], false],
            'db off, list with bad only'  => [['db' => false, 'notify_email' => ['nope']], true],
        ];
    }

    /** @param array<string,mixed> $config */
    #[\PHPUnit\Framework\Attributes\DataProvider('discardCases')]
    public function testWarnsWhenSubmissionsWouldBeDiscardedSameRuleAsTheFrontend(array $config, bool $warns): void
    {
        $result = (new SurveyLinter())->lint($config, $this->survey('email'));
        $paths  = array_column($result->warnings(), 'path');
        $this->assertSame($warns, in_array('notify_email', $paths, true));
    }

    public function testContactDataInSurveyTextsIsReportedWithItsPath(): void
    {
        $survey = ['pages' => [['elements' => [
            ['type' => 'html', 'name' => 'h', 'html' => '<p>Fragen an <a href="mailto:sekretariat@schule-a.de">sekretariat@schule-a.de</a>, Tel. 0721 / 123 456-0</p>'],
            ['type' => 'text', 'name' => 'x', 'title' => 'Ihre Telefonnummer', 'placeholder' => '+49 721 1234567'],
            ['type' => 'text', 'name' => 'y', 'title' => 'Geburtsjahr 2010-2011 und 12345'],
        ]]]];

        $paths = array_column((new SurveyLinter())->contactData($survey)->warnings(), 'path');

        $this->assertContains('pages[0].elements[0].html', $paths);
        $this->assertContains('pages[0].elements[1].placeholder', $paths);
        $this->assertNotContains('pages[0].elements[2].title', $paths, 'years and numbers are not phone numbers');
        $this->assertNotContains('pages[0].elements[1].title', $paths);
    }

    public function testContactDataOfARealSurveyIsFoundButNeverAnError(): void
    {
        $survey = json_decode((string)file_get_contents(__DIR__ . '/../../../../frontend/surveys/bs.json'), true);
        $result = (new SurveyLinter())->contactData($survey);
        $this->assertSame([], $result->errors());
    }

    public function testAcceptedEmailFieldNames(): void
    {
        foreach (SurveyLinter::EMAIL_FIELDS as $name) {
            $this->assertSame([], (new SurveyLinter())->lint(['db' => true], $this->survey($name))->warnings(), $name);
        }
    }

    public function testRealConfigTemplateMatchesRealSurveys(): void
    {
        $forms = require __DIR__ . '/../../../../frontend/config/forms-config-dist.php';
        $linter = new SurveyLinter();

        foreach ($forms as $key => $config) {
            $file = __DIR__ . '/../../../../frontend/surveys/' . ($config['form'] ?? '');
            if (!is_file($file)) {
                continue; // example entries without a survey file (e.g. "bk")
            }
            $survey = json_decode((string)file_get_contents($file), true);
            $result = $linter->lint($config, $survey);
            $this->assertSame([], $result->errors(), $key);
            $this->assertNotEmpty(SurveyFieldExtractor::dataKeys($survey), $key);
        }
        $this->addToAssertionCount(1);
    }
}
