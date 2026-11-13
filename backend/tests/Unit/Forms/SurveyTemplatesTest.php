<?php
declare(strict_types=1);

namespace Tests\Unit\Forms;

use App\Forms\SurveyBlocks;
use App\Forms\SurveyFieldExtractor;
use App\Forms\SurveyLinter;
use App\Forms\SurveyTemplates;
use App\Forms\SurveyValidator;
use PHPUnit\Framework\TestCase;

/**
 * Templates and examples an empty survey editor offers (AP6). Acceptance of AP6: a template passes "Prüfen" without a
 * warning, except the placeholder link to the privacy statement that the school has to replace.
 */
class SurveyTemplatesTest extends TestCase
{
    private const RESOURCES = __DIR__ . '/../../../resources/survey-templates';
    private const FRONTEND_SURVEYS = __DIR__ . '/../../../../frontend/surveys';
    private const ASV = __DIR__ . '/../../fixtures/asv/asv-bw-import.json';

    /** @var list<array{key:string, kind:string, text:string}> */
    private array $all;

    protected function setUp(): void
    {
        $this->all = SurveyTemplates::all();
    }

    public function testTemplatesAndExamplesAreListed(): void
    {
        $this->assertSame(['leer', 'rueckmeldung', 'rueckmeldung_ohne_email', 'veranstaltung', 'schueleranmeldung'], $this->keys('template'));
        $this->assertSame(['bs', 'gs', 'vabo', 'zq', 'meister'], $this->keys('example'));
    }

    public function testEveryFileInTheDirectoriesIsInTheManifest(): void
    {
        $manifest = json_decode((string)file_get_contents(self::RESOURCES . '/manifest.json'), true, 16, JSON_THROW_ON_ERROR);
        $listed   = array_merge(...array_map(static fn(string $s): array => array_column($manifest[$s], 'file'), ['blocks', 'templates', 'examples']));
        foreach (['blocks', 'templates', 'examples'] as $dir) {
            foreach (glob(self::RESOURCES . "/{$dir}/*.json") ?: [] as $file) {
                $this->assertContains("{$dir}/" . basename($file), $listed, 'not in manifest.json');
            }
        }
    }

    public function testTemplatesPassValidatorAndLinterWithoutWarningsExceptThePlaceholderLink(): void
    {
        foreach ($this->of('template') as $t) {
            ['result' => $result, 'survey' => $survey] = (new SurveyValidator())->validate($t['text']);
            $this->assertSame([], $result->errors(), $t['key']);
            $warnings = array_filter($result->warnings(), static fn(array $w): bool => !str_contains($w['message'], 'Platzhalter-Adresse'));
            $this->assertSame([], array_values($warnings), $t['key']);
            $this->assertSame([], (new SurveyLinter())->lint(['db' => true], $survey)->warnings(), $t['key']);
        }
    }

    public function testNameAndEmailAreRequiredWhereTheTemplateAsksForBoth(): void
    {
        foreach (['leer', 'rueckmeldung', 'veranstaltung', 'schueleranmeldung'] as $key) {
            $f = (new SurveyLinter())->requiredFields($this->decoded($key));
            $this->assertTrue($f['name']['required'] && $f['email']['required'], $key);
        }
        $f = (new SurveyLinter())->requiredFields($this->decoded('rueckmeldung_ohne_email'));
        $this->assertTrue($f['name']['required']);
        $this->assertFalse($f['email']['present'], 'no e-mail at all (decision 7: leaving it out is deliberate)');
    }

    public function testTheTextIsWrittenLikeTheCreatorExportsIt(): void
    {
        foreach ($this->of('template') as $t) {
            $this->assertStringStartsWith("{\n  \"", $t['text'], $t['key']);
            $this->assertStringEndsWith("}\n", $t['text'], $t['key']);
            $this->assertStringNotContainsString('\\/', $t['text'], $t['key']);
            $this->assertDoesNotMatchRegularExpression('/\\\\u00[c-f]/i', $t['text'], $t['key'] . ': umlauts as they are');
            $this->assertStringNotContainsString('$block', $t['text'], $t['key']);
        }
    }

    public function testTheSchoolRegistrationUsesAsvNamesOnly(): void
    {
        $columns = json_decode((string)file_get_contents(self::ASV), true, 16, JSON_THROW_ON_ERROR)['columns'];
        $fields  = array_keys(SurveyFieldExtractor::fields($this->decoded('schueleranmeldung')));
        $other   = array_values(array_diff($fields, $columns));
        // consent_… is not stored; Anmerkungen is for the office (ASV ignores columns it does not know, like bs.json shows)
        $this->assertSame(['consent_DatenschutzOk', 'Anmerkungen'], $other);
    }

    public function testInTheSchoolRegistrationTheFirstGuardianIsRequiredAndOnlyForMinors(): void
    {
        $survey = $this->decoded('schueleranmeldung');
        $page   = array_values(array_filter($survey['pages'], static fn(array $p): bool => $p['name'] === 'page_eltern'))[0];
        $this->assertSame('{Alter} < 18', $page['visibleIf']);
        $this->assertSame([['name' => 'Alter', 'expression' => 'age({Geburtstag})']], $survey['calculatedValues']);

        $fields = SurveyFieldExtractor::fields($survey);
        foreach (['Erz1Art', 'Erz1Vorname', 'Erz1Name', 'Erz1Ort'] as $name) {
            $this->assertTrue($fields[$name]['required'], $name);
        }
        $this->assertFalse($fields['Erz2Name']['required']);
        $this->assertFalse($fields['Erz1Email']['required']);
    }

    public function testTheTemplateTakesTheBlockAsItIsApartFromRequire(): void
    {
        $person = array_column(SurveyBlocks::all(), 'element', 'key')['person'];
        $survey = $this->decoded('schueleranmeldung');
        $this->assertSame($person, $survey['pages'][1]['elements'][0]);
    }

    public function testExamplesAreTheFilesOfTheFrontendByteForByte(): void
    {
        // until frontend/surveys/ goes away (PLAN-3.2.0, "frontend/surveys/ ablösen", step 4); then delete this test
        foreach ($this->of('example') as $e) {
            $this->assertSame((string)file_get_contents(self::FRONTEND_SURVEYS . "/{$e['key']}.json"), $e['text'],
                "examples/{$e['key']}.json differs from frontend/surveys/{$e['key']}.json: copy the newer one over the other");
        }
    }

    public function testExamplesHaveNoErrors(): void
    {
        foreach ($this->of('example') as $e) {
            $this->assertSame([], (new SurveyValidator())->validate($e['text'])['result']->errors(), $e['key']);
        }
    }

    public function testEveryEntryHasATitleAndADescription(): void
    {
        $messages = (require __DIR__ . '/../../../config/messages.php')['builder']['templates'];
        foreach ($this->all as $t) {
            $this->assertIsString($messages[$t['key']]['title'] ?? null, $t['key']);
            $this->assertIsString($messages[$t['key']]['description'] ?? null, $t['key']);
        }
        foreach (SurveyTemplates::KINDS as $kind) {
            $this->assertIsString($messages['group'][$kind] ?? null, $kind);
        }
        $blocks = (require __DIR__ . '/../../../config/messages.php')['builder']['blocks'];
        foreach (SurveyBlocks::all() as $b) {
            $this->assertIsString($blocks[$b['block']]['title'] ?? null, $b['block']);
            $this->assertIsString($blocks[$b['block']]['description'] ?? null, $b['block']);
        }
    }

    public function testAnUnknownBlockOrRequiredFieldIsADefect(): void
    {
        foreach ([
            '{"pages":[{"name":"p","elements":[{"$block":"gibtsnicht"}]}]}',
            '{"pages":[{"name":"p","elements":[{"$block":"anmerkungen","require":["Vorname"]}]}]}',
            '{"pages":[{"name":"p","elements":[{"$block":"anmerkungen","title":"x"}]}]}',
        ] as $template) {
            $dir = $this->scratch($template);
            try {
                SurveyTemplates::all($dir);
                $this->fail('accepted: ' . $template);
            } catch (\RuntimeException $e) {
                $this->assertNotSame('', $e->getMessage());
            } finally {
                array_map('unlink', glob($dir . '/*/*') ?: []);
                @unlink($dir . '/manifest.json');
                array_map('rmdir', glob($dir . '/*', GLOB_ONLYDIR) ?: []);
                @rmdir($dir);
            }
        }
    }

    // ---- helpers ----------------------------------------------------------------------------------------------------

    /** @return list<string> */
    private function keys(string $kind): array
    {
        return array_column($this->of($kind), 'key');
    }

    /** @return list<array{key:string, kind:string, text:string}> */
    private function of(string $kind): array
    {
        return array_values(array_filter($this->all, static fn(array $t): bool => $t['kind'] === $kind));
    }

    /** @return array<string,mixed> */
    private function decoded(string $key): array
    {
        foreach ($this->all as $t) {
            if ($t['key'] === $key) {
                return json_decode($t['text'], true, 64, JSON_THROW_ON_ERROR);
            }
        }
        $this->fail("no template {$key}");
    }

    /** A copy of the resources with one template of our own. */
    private function scratch(string $template): string
    {
        $dir = sys_get_temp_dir() . '/ondisos-templates-' . bin2hex(random_bytes(4));
        mkdir($dir . '/blocks', 0777, true);
        mkdir($dir . '/templates');
        copy(self::RESOURCES . '/blocks/anmerkungen.json', $dir . '/blocks/anmerkungen.json');
        file_put_contents($dir . '/templates/t.json', $template);
        file_put_contents($dir . '/manifest.json', json_encode([
            'blocks'    => [['key' => 'anmerkungen', 'file' => 'blocks/anmerkungen.json', 'group' => 'general']],
            'templates' => [['key' => 't', 'file' => 'templates/t.json']],
        ]));
        return $dir;
    }
}
