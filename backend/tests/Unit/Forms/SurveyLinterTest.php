<?php
declare(strict_types=1);

namespace Tests\Unit\Forms;

use App\Forms\SurveyFieldExtractor;
use App\Forms\SurveyLinter;
use PHPUnit\Framework\TestCase;

class SurveyLinterTest extends TestCase
{
    /**
     * A survey whose given fields are all required; "Name" and an e-mail field are added unless listed, so the
     * name/e-mail rule only fires in the tests that are about it.
     *
     * @return array<string,mixed>
     */
    private function survey(string ...$names): array
    {
        $all = array_values(array_unique([...$names, ...(array_intersect($names, SurveyLinter::NAME_FIELDS) ? [] : ['Name']),
            ...(array_intersect($names, SurveyLinter::EMAIL_FIELDS) ? [] : ['email'])]));
        return [
            'calculatedValues' => [['name' => 'Alter', 'expression' => 'age({Geburtstag})']],
            'pages' => [['elements' => array_map(fn ($n) => ['type' => 'text', 'name' => $n, 'isRequired' => true], $all)]],
        ];
    }

    /** A survey with exactly these elements (no additions). @param list<array<string,mixed>> $elements @return array<string,mixed> */
    private function raw(array $elements): array
    {
        return ['pages' => [['elements' => $elements]]];
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
        $survey = $this->raw([['type' => 'text', 'name' => 'Name', 'isRequired' => true], ['type' => 'text', 'name' => 'Vorname', 'isRequired' => true]]);
        $linter = new SurveyLinter();

        $this->assertSame(['email'], array_column($linter->lint(['db' => true], $survey)->warnings(), 'path'));
        $this->assertSame(['email'], array_column($linter->lint([], $survey)->warnings(), 'path'), 'db defaults to true');
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

    public function testMissingNameFieldWarnsLikeAMissingEmailField(): void
    {
        $survey = $this->raw([['type' => 'text', 'name' => 'Vorname', 'isRequired' => true], ['type' => 'text', 'name' => 'email', 'isRequired' => true]]);

        $r = (new SurveyLinter())->lint(['db' => true], $survey);

        $this->assertSame(['name'], array_column($r->warnings(), 'path'));
        $this->assertStringContainsString('Name', $r->warnings()[0]['message']);
        $this->assertTrue($r->isValid(), 'a warning, not an error: drafts may be unfinished');
    }

    /** @return array<string,array{0:array<string,mixed>,1:list<string>}> */
    public static function requiredCases(): array
    {
        $f = static fn (string $n, array $extra = []): array => ['type' => 'text', 'name' => $n] + $extra;
        return [
            'both required'              => [[$f('Name', ['isRequired' => true]), $f('email', ['isRequired' => true])], []],
            'name not required'          => [[$f('Name'), $f('email', ['isRequired' => true])], ['name']],
            'email not required'         => [[$f('Name', ['isRequired' => true]), $f('E-Mail')], ['email']],
            'required only conditionally' => [[$f('Name', ['isRequired' => true, 'visibleIf' => '{x} = 1']), $f('email', ['isRequired' => true, 'requiredIf' => '{x} = 1'])], ['name', 'email']],
            'isRequired as string'       => [[$f('Name', ['isRequired' => 'true']), $f('email', ['isRequired' => true])], ['name']],
            'lower case name'            => [[$f('name', ['isRequired' => true]), $f('Email', ['isRequired' => true])], []],
            'neither present'            => [[$f('Vorname', ['isRequired' => true])], ['name', 'email']],
            'nested in a panel'          => [[['type' => 'panel', 'name' => 'p', 'elements' => [$f('Name', ['isRequired' => true]), $f('email1', ['isRequired' => true])]]], []],
        ];
    }

    /**
     * @param list<array<string,mixed>> $elements
     * @param list<string> $warnedSlots
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('requiredCases')]
    public function testNameAndEmailMustBePresentAndAlwaysRequired(array $elements, array $warnedSlots): void
    {
        $r = (new SurveyLinter())->lint(['db' => true], ['pages' => [['elements' => $elements]]]);
        $this->assertSame($warnedSlots, array_column($r->warnings(), 'path'));
    }

    public function testNoNameOrEmailRulesWhenTheFormStoresNothingInTheBackend(): void
    {
        $r = (new SurveyLinter())->lint(['db' => false, 'notify_email' => 'a@b.de'], $this->raw([['type' => 'text', 'name' => 'Vorname']]));
        $this->assertSame([], $r->warnings());
    }

    public function testRequiredFieldsReportForTheEditorChecklist(): void
    {
        $info = (new SurveyLinter())->requiredFields($this->raw([
            ['type' => 'text', 'name' => 'Name', 'isRequired' => true],
            ['type' => 'text', 'name' => 'E-Mail'],
        ]));

        $this->assertSame(['present' => true, 'field' => 'Name', 'required' => true], $info['name']);
        $this->assertSame(['present' => true, 'field' => 'E-Mail', 'required' => false], $info['email']);
        $this->assertSame(['present' => false, 'field' => null, 'required' => false], (new SurveyLinter())->requiredFields([])['name']);
    }

    public function testTheRealSurveysHaveTheirNameAndEmailFieldsOrWarnAccordingly(): void
    {
        foreach (['bs', 'vabo', 'zq'] as $file) {
            $survey = json_decode((string)file_get_contents(__DIR__ . "/../../../../frontend/surveys/{$file}.json"), true);
            $info = (new SurveyLinter())->requiredFields($survey);
            // Whatever the result is, it must be consistent with what lint() reports.
            $paths = array_column((new SurveyLinter())->lint(['db' => true], $survey)->warnings(), 'path');
            $this->assertSame(!$info['name']['present'] || !$info['name']['required'], in_array('name', $paths, true), $file);
            $this->assertSame(!$info['email']['present'] || !$info['email']['required'], in_array('email', $paths, true), $file);
        }
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
