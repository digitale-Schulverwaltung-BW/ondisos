<?php
// src/Services/SpreadsheetBuilder.php

declare(strict_types=1);

namespace App\Services;

use App\Models\Anmeldung;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Font;
use PhpOffice\PhpSpreadsheet\Style\Alignment;

class SpreadsheetBuilder
{
    private Spreadsheet $spreadsheet;
    private ExportService $exportService;

    public function __construct(ExportService $exportService)
    {
        $this->spreadsheet = new Spreadsheet();
        $this->exportService = $exportService;
    }

    /**
     * Build Excel spreadsheet from anmeldungen data
     * 
     * @param Anmeldung[] $anmeldungen
     * @param string[] $columns
     */
    public function build(array $anmeldungen, array $columns, array $metadata): Spreadsheet
    {
        $sheet = $this->spreadsheet->getActiveSheet();
        $sheet->setTitle('Anmeldungen');

        // Determine if we should include Formular column
        // Hide column if a specific form is filtered (metadata['filter'] is set and not empty)
        $hasFilter = isset($metadata['filter']) 
            && $metadata['filter'] !== null 
            && $metadata['filter'] !== '';

        $includeFormular = !$hasFilter; // Show column only when NO filter

        // Build header row
        $this->buildHeader($sheet, $columns, $includeFormular);

        // Build data rows
        $this->buildDataRows($sheet, $anmeldungen, $columns, $includeFormular);

        // Apply styling
        $this->applyFormatting($sheet);

        // Add metadata sheet
        $this->addMetadataSheet($metadata);

        return $this->spreadsheet;
    }

    /**
     * Build header row
     */
    private function buildHeader($sheet, array $columns, bool $includeFormular = true): void
    {
        $colNum = 1;
        $rowNum = 1;

        // Fixed columns
        $sheet->setCellValue($this->colLetter($colNum++) . $rowNum, 'ID');
        // Only add Formular column if we're exporting multiple forms
        if ($includeFormular) {
            $sheet->setCellValue($this->colLetter($colNum++) . $rowNum, 'Formular');
        }
        $sheet->setCellValue($this->colLetter($colNum++) . $rowNum, 'Version');
        $sheet->setCellValue($this->colLetter($colNum++) . $rowNum, 'Name');
        $sheet->setCellValue($this->colLetter($colNum++) . $rowNum, 'E-Mail');
        $sheet->setCellValue($this->colLetter($colNum++) . $rowNum, 'Status');
        $sheet->setCellValue($this->colLetter($colNum++) . $rowNum, 'Erstellt am');

        // Dynamic columns from data
        // Skip fields that are already exported as fixed columns
        $skipFields = ['name', 'Name', 'email', 'email1', 'Email', 'E-mail', 'E-Mail'];

        foreach ($columns as $col) {
            if (in_array($col, $skipFields, true)) {
                continue;
            }
            $sheet->setCellValue($this->colLetter($colNum++) . $rowNum, $this->humanizeColumnName($col));
        }

        // Style header row
        $lastCol = $this->colLetter($colNum - 1);
        $headerRange = "A1:{$lastCol}1";
        
        $sheet->getStyle($headerRange)->applyFromArray([
            'font' => [
                'bold' => true,
                'color' => ['rgb' => 'FFFFFF']
            ],
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => ['rgb' => '4472C4']
            ],
            'alignment' => [
                'horizontal' => Alignment::HORIZONTAL_CENTER,
                'vertical' => Alignment::VERTICAL_CENTER
            ]
        ]);
    }

    /**
     * Build data rows
     * 
     * @param Anmeldung[] $anmeldungen
     * @param string[] $columns
     */
    private function buildDataRows($sheet, array $anmeldungen, array $columns, bool $includeFormular = true): void
    {
        $rowNum = 2; // Start after header

        foreach ($anmeldungen as $anmeldung) {
            $colNum = 1;

            // Fixed columns
            $sheet->setCellValue($this->colLetter($colNum++) . $rowNum, $anmeldung->id);
            
            // Only add Formular column if we're exporting multiple forms
            if ($includeFormular) {
                $sheet->setCellValue($this->colLetter($colNum++) . $rowNum, $anmeldung->formular);
            }
            $sheet->setCellValue($this->colLetter($colNum++) . $rowNum, $anmeldung->formularVersion ?? '');
            $sheet->setCellValue($this->colLetter($colNum++) . $rowNum, $anmeldung->name ?? '');
            $sheet->setCellValue($this->colLetter($colNum++) . $rowNum, $anmeldung->email ?? '');
            $sheet->setCellValue($this->colLetter($colNum++) . $rowNum, $anmeldung->status);
            $sheet->setCellValue($this->colLetter($colNum++) . $rowNum, $anmeldung->createdAt->format('d.m.Y H:i:s'));

            // Dynamic columns from data (via ExportService für Teilort-Enrichment)
            $data = $this->exportService->getEffectiveData($anmeldung);

            // Skip fields that are already exported as fixed columns
            $skipFields = ['name', 'Name', 'email', 'email1', 'Email', 'E-mail', 'E-Mail'];

            foreach ($columns as $key) {
                // Skip if this field is already in the fixed columns
                if (in_array($key, $skipFields, true)) {
                    continue;
                }

                $value = $data[$key] ?? null;
                $formatted = $this->exportService->formatCellValue($value);
                $sheet->setCellValue($this->colLetter($colNum++) . $rowNum, $formatted);
            }

            $rowNum++;
        }
    }

    /**
     * Apply formatting to the entire sheet
     */
    private function applyFormatting($sheet): void
    {
        // Auto-size all columns
        $highestColumn = $sheet->getHighestColumn();
        $highestRow = $sheet->getHighestRow();
        
        foreach ($sheet->getColumnIterator() as $column) {
            $sheet->getColumnDimension($column->getColumnIndex())->setAutoSize(true);
        }

        // Add autofilter
        $sheet->setAutoFilter("A1:{$highestColumn}1");

        // Freeze header row
        $sheet->freezePane('A2');

        // Zebra striping for better readability
        for ($row = 2; $row <= $highestRow; $row++) {
            if ($row % 2 === 0) {
                $sheet->getStyle("A{$row}:{$highestColumn}{$row}")->applyFromArray([
                    'fill' => [
                        'fillType' => Fill::FILL_SOLID,
                        'startColor' => ['rgb' => 'F2F2F2']
                    ]
                ]);
            }
        }
    }

    /**
     * Add metadata sheet with export info
     */
    private function addMetadataSheet(array $metadata): void
    {
        $metaSheet = $this->spreadsheet->createSheet();
        $metaSheet->setTitle('Export-Info');

        $metaSheet->setCellValue('A1', 'Export-Informationen');
        $metaSheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);

        $row = 3;
        $metaSheet->setCellValue('A' . $row, 'Export-Datum:');
        $metaSheet->setCellValue('B' . $row++, $metadata['exportDate']->format('d.m.Y H:i:s'));

        $metaSheet->setCellValue('A' . $row, 'Formular-Filter:');
        $metaSheet->setCellValue('B' . $row++, $metadata['filter'] ?? 'Alle Formulare');

        $metaSheet->setCellValue('A' . $row, 'Anzahl Einträge:');
        $metaSheet->setCellValue('B' . $row++, $metadata['totalRows']);

        // Style metadata
        $metaSheet->getColumnDimension('A')->setWidth(20);
        $metaSheet->getColumnDimension('B')->setWidth(30);
        $metaSheet->getStyle('A3:A' . ($row - 1))->getFont()->setBold(true);

        // Set main sheet as active
        $this->spreadsheet->setActiveSheetIndex(0);
    }

    /**
     * Save spreadsheet to output stream
     */
    public function save(): void
    {
        $writer = new Xlsx($this->spreadsheet);
        $writer->save('php://output');
    }

    /**
     * Convert column number to Excel letter (1 = A, 27 = AA, etc.)
     */
    private function colLetter(int $col): string
    {
        $letter = '';
        
        while ($col > 0) {
            $col--;
            $letter = chr(65 + ($col % 26)) . $letter;
            $col = intdiv($col, 26);
        }
        
        return $letter;
    }

    /**
     * Humanize column name for header
     */
    private function humanizeColumnName(string $name): string
    {
        // Return field name as-is for downstream tool compatibility
        return $name;

        /* Original humanization logic (disabled for field name compatibility):
        // Convert snake_case to spaces
        $label = str_replace('_', ' ', $name);

        // Convert camelCase to spaces
        $label = preg_replace('/([a-z])([A-Z])/', '$1 $2', $label);

        // Capitalize first letter of each word
        return ucwords($label);
        */
    }
}