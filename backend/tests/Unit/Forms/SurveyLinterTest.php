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
        $this->assertSame([], $linter->lint(['db' => false], $survey)->warnings());
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
