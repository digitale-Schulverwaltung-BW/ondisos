<?php
// src/Services/ExportService.php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\AnmeldungRepository;
use App\Models\Anmeldung;
use App\Services\StatusService;
use App\Config\Config;

class ExportService
{
    public function __construct(
        private AnmeldungRepository $repository,
        private StatusService $statusService
    ) {}

    /**
     * Get export data with all columns
     * 
     * @return array{
     *   rows: Anmeldung[],
     *   columns: string[],
     *   metadata: array
     * }
     */
    public function getExportData(?string $formularFilter = null): array
    {
        // Get all non-deleted anmeldungen
        $anmeldungen = $this->repository->findForExport($formularFilter);

        // Auto-mark as read if enabled
        $config = Config::getInstance();
        if ($config->autoMarkAsRead) {
            $ids = array_map(fn($a) => $a->id, $anmeldungen);
            $this->statusService->markMultipleAsRead($ids);
        }

        // Extract all unique column names from data
        $columns = $this->extractColumns($anmeldungen);

        // Sort columns alphabetically for consistency
        sort($columns);

        return [
            'rows' => $anmeldungen,
            'columns' => $columns,
            'metadata' => [
                'exportDate' => new \DateTimeImmutable(),
                'filter' => $formularFilter,
                'totalRows' => count($anmeldungen)
            ]
        ];
    }

    /**
     * Extract all unique column names from anmeldungen data
     * 
     * @param Anmeldung[] $anmeldungen
     * @return string[]
     */
    private function extractColumns(array $anmeldungen): array
    {
        $columnSet = [];

        foreach ($anmeldungen as $anmeldung) {
            if ($anmeldung->data === null || !is_array($anmeldung->data)) {
                continue;
            }

            foreach ($anmeldung->data as $key => $value) {
                // Skip nested arrays for now, or flatten them
                if (!is_array($value)) {
                    $columnSet[$key] = true;
                } else {
                    // Option: flatten array columns
                    $columnSet[$key] = true;
                }
            }
        }

        return array_keys($columnSet);
    }

    /**
     * Get cell value for export (handles arrays, nulls, dates, etc.)
     */
    public function formatCellValue(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        if (is_bool($value)) {
            return $value ? 'Ja' : 'Nein';
        }

        if (is_array($value)) {
            // Flatten array to comma-separated string
            return $this->flattenArray($value);
        }

        // Check if value looks like an ISO date (YYYY-MM-DD or YYYY-MM-DD HH:MM:SS)
        if (is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}/', $value)) {
            try {
                $date = new \DateTimeImmutable($value);
                // Format as German date (dd.mm.yyyy)
                return $date->format('d.m.Y');
            } catch (\Exception $e) {
                // Not a valid date, return as-is
                return (string)$value;
            }
        }

        return (string)$value;
    }

    /**
     * Flatten array to string representation
     */
    private function flattenArray(array $arr): string
    {
        $result = [];

        foreach ($arr as $key => $value) {
            if (is_array($value)) {
                // Nested array: represent as JSON
                $result[] = json_encode($value, JSON_UNESCAPED_UNICODE);
            } else {
                $result[] = (string)$value;
            }
        }

        return implode(', ', $result);
    }

    /**
     * Generate filename for export
     */
    public function generateFilename(?string $formularFilter = null): string
    {
        $timestamp = date('Y-m-d_H-i');
        
        if ($formularFilter !== null && $formularFilter !== '') {
            // Sanitize form name for filename
            $sanitized = preg_replace('/[^a-zA-Z0-9_-]/', '_', $formularFilter);
            return "anmeldungen_{$sanitized}_{$timestamp}.xlsx";
        }

        return "anmeldungen_{$timestamp}.xlsx";
    }
}