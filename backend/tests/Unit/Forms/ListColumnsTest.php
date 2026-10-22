<?php
declare(strict_types=1);

namespace Tests\Unit\Forms;

use App\Forms\ListColumns;
use PHPUnit\Framework\TestCase;

class ListColumnsTest extends TestCase
{
    /** @return array<string,mixed> */
    private function survey(): array
    {
        return ['pages' => [['elements' => [
            ['type' => 'text', 'name' => 'vorname', 'title' => 'Vorname: '],
            ['type' => 'file', 'name' => 'zeugnis'],
            ['type' => 'signaturepad', 'name' => 'unterschrift'],
            ['type' => 'text', 'name' => 'kennwort', 'inputType' => 'password'],
            ['type' => 'radiogroup', 'name' => 'form', 'title' => ['default' => 'Form', 'de' => 'Unterrichtsform'], 'choices' => [
                ['value' => 'vz', 'text' => 'Vollzeit', 'elements' => [
                    ['type' => 'dropdown', 'name' => 'schwerpunkt', 'choices' => [['value' => 'item1', 'text' => 'Energie'], 'Kommunikation']],
                ]],
                ['value' => 'tz', 'text' => 'Teilzeit'],
            ]],
            ['type' => 'checkbox', 'name' => 'tage', 'choices' => [1, 2, 3], 'showOtherItem' => true],
            ['type' => 'boolean', 'name' => 'bafoeg'],
            ['type' => 'rating', 'name' => 'note'],
            ['type' => 'text', 'name' => 'geburtsdatum', 'inputType' => 'date'],
            ['type' => 'matrix', 'name' => 'm'],
            ['type' => 'paneldynamic', 'name' => 'kinder'],
        ]]], 'calculatedValues' => [['name' => 'alter', 'expression' => '1']]];
    }

    public function testCatalogOffersOnlyDisplayableFieldsInFormOrder(): void
    {
        $catalog = ListColumns::catalog($this->survey());

        $this->assertSame(
            ['vorname', 'form', 'schwerpunkt', 'tage', 'bafoeg', 'note', 'geburtsdatum', 'alter'],
            array_map('strval', array_keys($catalog))
        );
    }

    public function testCatalogUsesGermanTitleAndFallsBackToName(): void
    {
        $catalog = ListColumns::catalog($this->survey());

        $this->assertSame('Unterrichtsform', $catalog['form']['label']);
        $this->assertSame('Vorname', $catalog['vorname']['label']);
        $this->assertSame('schwerpunkt', $catalog['schwerpunkt']['label']);
    }

    public function testChoiceColumnMapsValuesToTexts(): void
    {
        $catalog = ListColumns::catalog($this->survey());

        $this->assertSame(['vz' => 'Vollzeit', 'tz' => 'Teilzeit'], $catalog['form']['choices']);
        $this->assertSame('Vollzeit', ListColumns::format('vz', $catalog['form']));
        $this->assertSame('Kommunikation', ListColumns::format('Kommunikation', $catalog['schwerpunkt']));
    }

    public function testUnknownChoiceValueIsShownAsStored(): void
    {
        $catalog = ListColumns::catalog($this->survey());

        $this->assertSame('alt', ListColumns::format('alt', $catalog['form']));
    }

    public function testCheckboxAnswersAreJoinedWithOtherChoice(): void
    {
        $catalog = ListColumns::catalog($this->survey(), ['other' => 'Andere']);

        $this->assertTrue($catalog['tage']['multi']);
        $this->assertSame('1, 3, Andere', ListColumns::format([1, 3, 'other'], $catalog['tage']));
    }

    public function testBooleanUsesGivenLabels(): void
    {
        $catalog = ListColumns::catalog($this->survey(), ['yes' => 'J', 'no' => 'N']);

        $this->assertSame('J', ListColumns::format(true, $catalog['bafoeg']));
        $this->assertSame('N', ListColumns::format(false, $catalog['bafoeg']));
    }

    public function testRatingWithoutValuesOffersDefaultSteps(): void
    {
        $catalog = ListColumns::catalog($this->survey());

        $this->assertSame(['1', '2', '3', '4', '5'], array_map('strval', array_keys($catalog['note']['choices'])));
        $this->assertTrue($catalog['note']['numeric']);
    }

    public function testDateIsShownInGermanFormat(): void
    {
        $catalog = ListColumns::catalog($this->survey());

        $this->assertSame('24.12.2008', ListColumns::format('2008-12-24', $catalog['geburtsdatum']));
    }

    public function testMissingOrStructuredValueGivesEmptyText(): void
    {
        $catalog = ListColumns::catalog($this->survey());

        $this->assertSame('', ListColumns::format(null, $catalog['vorname']));
        $this->assertSame('', ListColumns::format(['a' => 'b'], $catalog['vorname']));
    }

    public function testSelectKeepsKnownNamesInCatalogOrderAndIgnoresJunk(): void
    {
        $catalog = ListColumns::catalog($this->survey());

        $this->assertSame(['form', 'bafoeg'], ListColumns::select(['bafoeg', 'zeugnis', 'form', ['x'], 'form'], $catalog));
        $this->assertSame([], ListColumns::select('form', $catalog));
    }

    public function testSelectLimitsNumberOfColumns(): void
    {
        $elements = [];
        for ($i = 0; $i < 12; $i++) {
            $elements[] = ['type' => 'text', 'name' => "f{$i}"];
        }
        $catalog = ListColumns::catalog(['elements' => $elements]);

        $this->assertCount(ListColumns::MAX_COLUMNS, ListColumns::select(array_keys($catalog), $catalog));
    }

    public function testChoiceSortUsesChoiceOrderWithBoundValues(): void
    {
        $catalog = ListColumns::catalog($this->survey());

        $sort = ListColumns::orderBy($catalog['form'], 'asc');

        $this->assertStringEndsWith('CASE JSON_UNQUOTE(JSON_EXTRACT(data, ?)) WHEN ? THEN 0 WHEN ? THEN 1 ELSE 2 END ASC', $sort['sql']);
        $this->assertSame(['$."form"', '$."form"', 'vz', 'tz'], $sort['params']);
        $this->assertSame(substr_count($sort['sql'], '?'), count($sort['params']));
    }

    public function testCheckboxSortUsesFirstAnswer(): void
    {
        $catalog = ListColumns::catalog($this->survey());

        $this->assertSame('$."tage"[0]', ListColumns::orderBy($catalog['tage'], 'ASC')['params'][0]);
    }

    public function testTextSortIgnoresCase(): void
    {
        $catalog = ListColumns::catalog($this->survey());

        $sort = ListColumns::orderBy($catalog['vorname'], 'DESC');

        $this->assertStringContainsString('utf8mb4_unicode_ci DESC', $sort['sql']);
        $this->assertSame(['$."vorname"', '$."vorname"'], $sort['params']);
    }

    public function testEmptyAnswersSortLastAndDirectionIsWhitelisted(): void
    {
        $catalog = ListColumns::catalog($this->survey());

        $sort = ListColumns::orderBy($catalog['note'], 'ASC; DROP TABLE x');

        $this->assertStringStartsWith("COALESCE(JSON_UNQUOTE(JSON_EXTRACT(data, ?)), '') = '', ", $sort['sql']);
        $this->assertStringEndsWith(' DESC', $sort['sql']);
        $this->assertStringNotContainsString('DROP', $sort['sql']);
    }

    public function testFilterOnSingleChoiceComparesUnquotedValue(): void
    {
        $catalog = ListColumns::catalog($this->survey());

        $this->assertSame(
            ['sql' => 'JSON_UNQUOTE(JSON_EXTRACT(data, ?)) = ?', 'params' => ['$."form"', 'tz']],
            ListColumns::filterCondition($catalog['form'], 'tz')
        );
    }

    public function testFilterOnCheckboxKeepsJsonTypeOfValue(): void
    {
        $catalog = ListColumns::catalog($this->survey());

        $this->assertSame(
            ['sql' => 'JSON_CONTAINS(data, ?, ?)', 'params' => ['2', '$."tage"']],
            ListColumns::filterCondition($catalog['tage'], '2')
        );
    }

    public function testFilterRejectsValueOutsideChoicesAndTextColumns(): void
    {
        $catalog = ListColumns::catalog($this->survey());

        $this->assertNull(ListColumns::filterCondition($catalog['form'], "x' OR 1=1"));
        $this->assertFalse(ListColumns::filterable($catalog['vorname']));
        $this->assertTrue(ListColumns::filterable($catalog['bafoeg']));
    }

    public function testJsonPathQuotesSpecialCharacters(): void
    {
        $this->assertSame('$."a \"b\" \\\\c"', ListColumns::jsonPath('a "b" \\c'));
    }
}
