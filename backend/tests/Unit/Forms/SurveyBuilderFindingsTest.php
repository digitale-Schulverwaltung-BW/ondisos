<?php
declare(strict_types=1);

namespace Tests\Unit\Forms;

use App\Forms\SurveyBuilderFindings;
use App\Forms\SurveyValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class SurveyBuilderFindingsTest extends TestCase
{
    /** @return array<string,array{0:string,1:list<string|int>}> */
    public static function paths(): array
    {
        return [
            'empty'              => ['', []],
            'one key'            => ['pages', ['pages']],
            'element name'       => ['pages[0].elements[1].name', ['pages', 0, 'elements', 1, 'name']],
            'top level element'  => ['elements[2]', ['elements', 2]],
            'nested panel'       => ['pages[0].elements[1].elements[3].visibleIf', ['pages', 0, 'elements', 1, 'elements', 3, 'visibleIf']],
            'big index'          => ['pages[12].elements[104]', ['pages', 12, 'elements', 104]],
            'calculated value'   => ['calculatedValues[0].expression', ['calculatedValues', 0, 'expression']],
            'localized text'     => ['pages[0].elements[0].html.default', ['pages', 0, 'elements', 0, 'html', 'default']],
            'name_or_email hint' => ['name_or_email', ['name_or_email']],
            'not a path'         => ['Zeile 3: kaputt', []],
            'dot first'          => ['.pages', []],
            'bracket first'      => ['[0].pages', []],
            'empty segment'      => ['pages..name', []],
            'unclosed bracket'   => ['pages[0', []],
            'negative index'     => ['pages[-1]', []],
        ];
    }

    /** @param list<string|int> $expected */
    #[DataProvider('paths')]
    public function testParsePath(string $path, array $expected): void
    {
        $this->assertSame($expected, SurveyBuilderFindings::parsePath($path));
    }

    public function testNumbersBecomeIntegersAndNamesStayStrings(): void
    {
        $parsed = SurveyBuilderFindings::parsePath('pages[0].elements[1]');
        $this->assertSame([0, 1], array_values(array_filter($parsed, 'is_int')));
        $this->assertSame(['pages', 'elements'], array_values(array_filter($parsed, 'is_string')));
    }

    public function testFromReportKeepsErrorsAndWarningsWithTheirSeverity(): void
    {
        $out = SurveyBuilderFindings::fromReport([
            'errors'   => [['path' => 'pages[0].elements[0].name', 'message' => 'doppelt', 'line' => 4, 'column' => null]],
            'warnings' => [['path' => '', 'message' => 'allgemein', 'line' => null]],
        ]);

        $this->assertSame([
            ['path' => ['pages', 0, 'elements', 0, 'name'], 'message' => 'doppelt', 'severity' => 'error'],
            ['path' => [], 'message' => 'allgemein', 'severity' => 'warning'],
        ], $out);
        $this->assertSame([], SurveyBuilderFindings::fromReport(null));
        $this->assertSame([], SurveyBuilderFindings::fromReport(['errors' => [], 'warnings' => []]));
    }

    /** The paths the validator really reports must come out as paths of the document. */
    public function testPathsOfRealValidatorFindingsPointAtTheirElements(): void
    {
        $survey = json_encode(['pages' => [['elements' => [
            ['type' => 'text', 'name' => 'a'],
            ['type' => 'panel', 'name' => 'p', 'elements' => [['type' => 'text', 'name' => 'a', 'visibleIf' => 'foo({a}) > 1']]],
            ['type' => 'html', 'name' => 'h', 'html' => '<script>x</script>'],
        ]]]], JSON_THROW_ON_ERROR);
        $result = (new SurveyValidator())->validate($survey)['result'];

        $findings = SurveyBuilderFindings::fromReport([
            'errors'   => array_map(static fn(array $e): array => ['path' => $e['path'], 'message' => $e['message']], $result->errors()),
            'warnings' => array_map(static fn(array $w): array => ['path' => $w['path'], 'message' => $w['message']], $result->warnings()),
        ]);

        $paths = array_map(static fn(array $f): string => json_encode($f['path'], JSON_THROW_ON_ERROR), $findings);
        $this->assertContains('["pages",0,"elements",1,"elements",0,"name"]', $paths, 'duplicate name inside the panel');
        $this->assertContains('["pages",0,"elements",2,"html"]', $paths, 'html outside the allowlist');
        $this->assertContains('["pages",0,"elements",1,"elements",0,"visibleIf"]', $paths, 'unknown function');
        foreach ($findings as $f) {
            $this->assertNotSame([], $f['path'], 'every finding of the validator has a usable path: ' . $f['message']);
        }
    }
}
