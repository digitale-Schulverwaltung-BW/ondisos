<?php
declare(strict_types=1);

namespace Tests\Unit\Forms;

use App\Forms\SurveyBlocks;
use App\Forms\SurveyFieldExtractor;
use App\Forms\SurveyValidator;
use PHPUnit\Framework\TestCase;

/**
 * The building blocks of the survey builder (AP6): readable, valid for the server, and for school registrations exactly
 * the column names and value keys of the ASV-BW import template (fixture from ASV-BW_Import.xlsx, Stand 10/2026).
 */
class SurveyBlocksTest extends TestCase
{
    private const ASV = __DIR__ . '/../../fixtures/asv/asv-bw-import.json';
    private const JS_FIXTURE = __DIR__ . '/../../js/fixtures/survey-blocks.json';

    /**
     * Values a block offers although the ASV value list does not have them. Geschlecht "d": the list 10/2026 knows only
     * m and w, and a test import (2026-11-13) rejects the row. Kept on purpose: the failed row tells the office to enter
     * the person by hand ("divers" is a supplement in ASV's master data that the import does not carry; PLAN-3.2.0).
     */
    private const VALUES_NOT_IN_ASV = ['Geschlecht' => ['d']];

    /** @var list<array{key:string, block:string, group:string, param:int|string|null, element:array<string,mixed>}> */
    private array $blocks;

    protected function setUp(): void
    {
        $this->blocks = SurveyBlocks::all();
    }

    public function testFixtureOfTheJavaScriptTestsEqualsTheBlocks(): void
    {
        $fixture = json_decode((string)file_get_contents(self::JS_FIXTURE), true, 64, JSON_THROW_ON_ERROR);
        $this->assertSame($this->blocks, $fixture, 'Refresh with: php backend/tools/dump-survey-blocks.php > backend/tests/js/fixtures/survey-blocks.json');
    }

    public function testKeysAreUniqueAndGroupsKnown(): void
    {
        $keys = array_column($this->blocks, 'key');
        $this->assertSame(array_values(array_unique($keys)), $keys);
        $this->assertGreaterThanOrEqual(10, count($keys));
        foreach ($this->blocks as $b) {
            $this->assertContains($b['group'], SurveyBlocks::GROUPS, $b['key']);
        }
    }

    public function testAParameterGivesOneBlockPerValueWithThePlaceholderReplaced(): void
    {
        $guardians = array_values(array_filter($this->blocks, static fn(array $b): bool => $b['block'] === 'erziehungsberechtigte'));
        $this->assertSame(['erziehungsberechtigte_1', 'erziehungsberechtigte_2'], array_column($guardians, 'key'));
        $this->assertContains('Erz1Name', SurveyBlocks::names($guardians[0]['element']));
        $this->assertContains('Erz2Email', SurveyBlocks::names($guardians[1]['element']));
        $this->assertStringNotContainsString('%N%', json_encode($this->blocks, JSON_THROW_ON_ERROR));
    }

    public function testEveryBlockPassesTheServerChecks(): void
    {
        $validator = new SurveyValidator();
        foreach ($this->blocks as $b) {
            $result = $validator->validate($this->survey([$b['element']]))['result'];
            $this->assertSame([], $result->errors(), $b['key']);
            // the only accepted warning: the placeholder link the school has to replace
            $warnings = array_filter($result->warnings(), static fn(array $w): bool => !str_contains($w['message'], 'Platzhalter-Adresse'));
            $this->assertSame([], array_values($warnings), $b['key']);
        }
    }

    public function testTheConsentBlockWarnsAboutItsPlaceholderLink(): void
    {
        $consent = $this->block('datenschutz');
        $warnings = (new SurveyValidator())->validate($this->survey([$consent['element']]))['result']->warnings();
        $this->assertCount(1, $warnings);
        $this->assertStringContainsString('example.com', $warnings[0]['message']);
        $this->assertContains('consent_DatenschutzOk', SurveyBlocks::names($consent['element']), 'consent_… is not stored (AnmeldungService)');
    }

    public function testASchoolRegistrationCanHoldEveryBlockExceptNameAndEmailAtOnce(): void
    {
        $elements = array_column(array_filter($this->blocks, static fn(array $b): bool => $b['block'] !== 'name_email'), 'element');
        $result = (new SurveyValidator())->validate($this->survey($elements))['result'];
        $this->assertSame([], $result->errors());

        $names = array_merge(...array_map([SurveyBlocks::class, 'names'], $elements));
        $this->assertSame(array_values(array_unique($names)), $names, 'no block shares a name (panels included) with another');
    }

    public function testFieldsOfSchoolRegistrationBlocksAreAsvColumns(): void
    {
        $columns = $this->asv()['columns'];
        foreach ($this->asvBlocks() as $b) {
            $fields = array_keys(SurveyFieldExtractor::fields($this->decoded([$b['element']])));
            $this->assertNotEmpty($fields, $b['key']);
            foreach ($fields as $name) {
                $this->assertContains($name, $columns, "{$b['key']}: {$name} is no column of the ASV import");
            }
        }
    }

    public function testChoicesOfSchoolRegistrationBlocksAreAsvKeys(): void
    {
        $listOf = [];
        foreach ($this->asv()['lists'] as $list) {
            foreach ($list['columns'] as $column) {
                $listOf[$column] = array_keys($list['values']);
            }
        }

        $checked = 0;
        foreach ($this->asvBlocks() as $b) {
            foreach ($this->questions($b['element']) as $q) {
                if (!isset($q['choices'])) {
                    continue;
                }
                $column = preg_replace('/^Erz\d/', 'Erz1', $q['name']);
                $this->assertArrayHasKey($column, $listOf, "{$b['key']}: {$q['name']} has answers, but ASV has no value list for it");
                $allowed = array_merge($listOf[$column], self::VALUES_NOT_IN_ASV[preg_replace('/^Erz\d/', '', $q['name'])] ?? []);
                foreach ($q['choices'] as $choice) {
                    $this->assertIsArray($choice, "{$q['name']}: answers carry the ASV key as value and its text");
                    $this->assertContains((string)$choice['value'], $allowed, "{$b['key']}: {$q['name']} = {$choice['value']}");
                    $checked++;
                }
            }
        }
        $this->assertGreaterThan(500, $checked, 'the country lists are checked too');
    }

    public function testTheHiddenTeilortKeepsItsValueOnSubmit(): void
    {
        $teilort = array_values(array_filter($this->questions($this->block('anschrift')['element']), static fn(array $q): bool => $q['name'] === 'Teilort'))[0];
        $this->assertSame('_autofill', $teilort['defaultValue'], 'ExportService::enrichTeilort() looks for it');
        $this->assertSame('none', $teilort['clearIfInvisible'], 'SurveyJS clears invisible values on complete otherwise');
    }

    public function testBrokenManifestIsADefectNotAnEmptyList(): void
    {
        $dir = sys_get_temp_dir() . '/ondisos-blocks-' . bin2hex(random_bytes(4));
        mkdir($dir);
        try {
            file_put_contents($dir . '/manifest.json', '{"blocks":[{"key":"x","file":"../secret.json","group":"general"}]}');
            $this->expectException(\RuntimeException::class);
            SurveyBlocks::all($dir);
        } finally {
            @unlink($dir . '/manifest.json');
            @rmdir($dir);
        }
    }

    // ---- helpers ----------------------------------------------------------------------------------------------------

    /** @param list<array<string,mixed>> $elements */
    private function survey(array $elements): string
    {
        return json_encode(['pages' => [['name' => 'page1', 'elements' => $elements]]], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    }

    /**
     * @param list<array<string,mixed>> $elements
     * @return array<string,mixed>
     */
    private function decoded(array $elements): array
    {
        return json_decode($this->survey($elements), true, 64, JSON_THROW_ON_ERROR);
    }

    /** @return array{key:string, block:string, group:string, param:int|string|null, element:array<string,mixed>} */
    private function block(string $key): array
    {
        foreach ($this->blocks as $b) {
            if ($b['key'] === $key) {
                return $b;
            }
        }
        $this->fail("no block {$key}");
    }

    /** @return list<array{key:string, block:string, group:string, param:int|string|null, element:array<string,mixed>}> */
    private function asvBlocks(): array
    {
        $out = array_values(array_filter($this->blocks, static fn(array $b): bool => $b['group'] === 'asv'));
        $this->assertNotEmpty($out);
        return $out;
    }

    /**
     * @param array<string,mixed> $element
     * @return list<array<string,mixed>> questions (not panels) inside the element
     */
    private function questions(array $element): array
    {
        if (($element['type'] ?? null) !== 'panel') {
            return [$element];
        }
        return array_merge([], ...array_map(fn(array $e): array => $this->questions($e), $element['elements'] ?? []));
    }

    /** @return array{columns:list<string>, lists:array<string, array{columns:list<string>, values:array<string,string>}>} */
    private function asv(): array
    {
        return json_decode((string)file_get_contents(self::ASV), true, 16, JSON_THROW_ON_ERROR);
    }
}
