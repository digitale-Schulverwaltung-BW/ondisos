<?php
declare(strict_types=1);

namespace Tests\Unit\Forms;

use App\Forms\SurveyDiff;
use PHPUnit\Framework\TestCase;

class SurveyDiffTest extends TestCase
{
    /** @return array<string,mixed> */
    private function survey(array ...$fields): array
    {
        return ['pages' => [['elements' => $fields]]];
    }

    public function testFieldChanges(): void
    {
        $old = $this->survey(['type' => 'text', 'name' => 'Vorname'], ['type' => 'text', 'name' => 'Alter'], ['type' => 'html', 'name' => 'h', 'html' => 'x']);
        $new = $this->survey(['type' => 'text', 'name' => 'Vorname'], ['type' => 'dropdown', 'name' => 'Alter'], ['type' => 'text', 'name' => 'Ort']);

        $f = SurveyDiff::fields($old, $new);

        $this->assertSame(['Ort'], $f['added']);
        $this->assertSame([], $f['removed']);
        $this->assertSame([['name' => 'Alter', 'from' => 'text', 'to' => 'dropdown']], $f['type_changed']);
    }

    public function testRenamedFieldShowsAsRemovedAndAdded(): void
    {
        $f = SurveyDiff::fields($this->survey(['type' => 'text', 'name' => 'Nachname']), $this->survey(['type' => 'text', 'name' => 'Familienname']));
        $this->assertSame(['Nachname'], $f['removed']);
        $this->assertSame(['Familienname'], $f['added']);
    }

    public function testFirstPublishAddsEverything(): void
    {
        $f = SurveyDiff::fields([], $this->survey(['type' => 'text', 'name' => 'a'], ['type' => 'text', 'name' => 'b']));
        $this->assertSame(['a', 'b'], $f['added']);
        $this->assertSame([], $f['removed']);
    }

    public function testNumericFieldNamesStayStrings(): void
    {
        $f = SurveyDiff::fields([], $this->survey(['type' => 'text', 'name' => '123']));
        $this->assertSame(['123'], $f['added']);
    }

    public function testIdenticalTextsEvenWithDifferentFormatting(): void
    {
        $d = SurveyDiff::lines('{"a":1,"b":{}}', "{\n  \"a\": 1,\n  \"b\": {}\n}");
        $this->assertTrue($d['identical']);
        $this->assertSame([], $d['hunks']);
    }

    public function testNormalizeKeepsEmptyObjects(): void
    {
        $this->assertStringContainsString('"b": {}', (string)SurveyDiff::normalize('{"b":{}}'));
        $this->assertNull(SurveyDiff::normalize('{nope'));
    }

    public function testSimpleChangeProducesOneHunkWithContext(): void
    {
        $old = json_encode(['a' => 1, 'b' => 2, 'c' => 3, 'd' => 4, 'e' => 5, 'f' => 6, 'g' => 7, 'h' => 8, 'i' => 9]);
        $new = json_encode(['a' => 1, 'b' => 2, 'c' => 3, 'd' => 4, 'e' => 50, 'f' => 6, 'g' => 7, 'h' => 8, 'i' => 9]);

        $d = SurveyDiff::lines((string)$old, (string)$new);

        $this->assertFalse($d['identical']);
        $this->assertSame(1, $d['added']);
        $this->assertSame(1, $d['removed']);
        $this->assertCount(1, $d['hunks']);
        $ops = array_column($d['hunks'][0], 'op');
        $this->assertSame([' ', ' ', ' ', '-', '+', ' ', ' ', ' '], $ops, 'three lines of context each side');
        $this->assertSame('    "e": 5,', $d['hunks'][0][3]['text']);
        $this->assertSame(6, $d['hunks'][0][3]['old']);
        $this->assertNull($d['hunks'][0][3]['new']);
        $this->assertSame(6, $d['hunks'][0][4]['new']);
    }

    public function testFarApartChangesGiveSeparateHunks(): void
    {
        $base = [];
        for ($i = 0; $i < 40; $i++) {
            $base['k' . $i] = $i;
        }
        $changed = $base;
        $changed['k2'] = 'x';
        $changed['k37'] = 'y';

        $d = SurveyDiff::lines((string)json_encode($base), (string)json_encode($changed));

        $this->assertCount(2, $d['hunks']);
        $this->assertSame(2, $d['added']);
        $this->assertSame(2, $d['removed']);
    }

    /**
     * Property check against a brute-force LCS: the edit script must reproduce the new text and be minimal.
     */
    public function testEditScriptReproducesTheNewTextAndIsMinimal(): void
    {
        mt_srand(7);
        for ($round = 0; $round < 60; $round++) {
            $old = [];
            $n = mt_rand(1, 25);
            for ($i = 0; $i < $n; $i++) {
                $old['f' . mt_rand(0, 12)] = mt_rand(0, 3);
            }
            $new = $old;
            $touch = min(count($new), mt_rand(1, 4));
            foreach ((array)array_rand($new, $touch) as $k) {
                $new[$k] = mt_rand(10, 13);
            }
            if (mt_rand(0, 1)) {
                unset($new[array_key_first($new)]);
            }
            $new['extra' . mt_rand(0, 5)] = 1;
            if ($round % 3 === 0) {
                $new = array_reverse($new, true);
            }

            $oldText = (string)SurveyDiff::normalize((string)json_encode($old));
            $newText = (string)SurveyDiff::normalize((string)json_encode($new));
            $d = SurveyDiff::lines($oldText, $newText, 1000);

            if ($oldText === $newText) {
                $this->assertTrue($d['identical']);
                continue;
            }
            $this->assertCount(1, $d['hunks'], 'huge context merges everything into one hunk');

            $rebuiltNew = [];
            $rebuiltOld = [];
            foreach ($d['hunks'][0] as $l) {
                if ($l['op'] !== '-') {
                    $rebuiltNew[] = $l['text'];
                }
                if ($l['op'] !== '+') {
                    $rebuiltOld[] = $l['text'];
                }
            }
            $this->assertSame($newText, implode("\n", $rebuiltNew), "round {$round}: new text");
            $this->assertSame($oldText, implode("\n", $rebuiltOld), "round {$round}: old text");

            $lcs = $this->lcsLength(explode("\n", $oldText), explode("\n", $newText));
            $this->assertSame(count(explode("\n", $oldText)) - $lcs, $d['removed'], "round {$round}: removed minimal");
            $this->assertSame(count(explode("\n", $newText)) - $lcs, $d['added'], "round {$round}: added minimal");
        }
    }

    /** @param list<string> $a @param list<string> $b */
    private function lcsLength(array $a, array $b): int
    {
        $prev = array_fill(0, count($b) + 1, 0);
        foreach ($a as $x) {
            $cur = [0];
            foreach ($b as $j => $y) {
                $cur[$j + 1] = $x === $y ? $prev[$j] + 1 : max($prev[$j + 1], $cur[$j]);
            }
            $prev = $cur;
        }
        return $prev[count($b)];
    }

    public function testHugeRewriteIsReportedAsTooLargeInsteadOfHanging(): void
    {
        $old = [];
        $new = [];
        for ($i = 0; $i < 900; $i++) {
            $old['a' . $i] = $i;
            $new['b' . $i] = $i;
        }

        $started = microtime(true);
        $d = SurveyDiff::lines((string)json_encode($old), (string)json_encode($new));

        $this->assertTrue($d['too_large']);
        $this->assertLessThan(5.0, microtime(true) - $started);
    }

    public function testInvalidJsonGivesNoDiff(): void
    {
        $d = SurveyDiff::lines('{"a":1}', '{nope');
        $this->assertTrue($d['too_large']);
        $this->assertSame([], $d['hunks']);
    }

    public function testRealSurveyAgainstItselfWithOneRenamedField(): void
    {
        $text   = (string)file_get_contents(__DIR__ . '/../../../../frontend/surveys/bs.json');
        $fields = \App\Forms\SurveyFieldExtractor::fieldNames((array)json_decode($text, true));
        $victim = $fields[1];
        $changed = preg_replace('/"name":\s*"' . preg_quote($victim, '/') . '"/', '"name": "UMBENANNT"', $text);
        $this->assertTrue($text !== $changed, "replacement must change the text");

        $d = SurveyDiff::lines($text, (string)$changed);

        $this->assertFalse($d['too_large']);
        $this->assertGreaterThan(0, $d['added']);
        $this->assertSame($d['added'], $d['removed']);
        $f = SurveyDiff::fields((array)json_decode($text, true), (array)json_decode((string)$changed, true));
        $this->assertSame([$victim], $f['removed']);
        $this->assertSame(['UMBENANNT'], $f['added']);
    }
}
