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

        // Auto-mark as exported if enabled
        $config = Config::getInstance();
        if ($config->autoMarkAsRead) {
            $ids = array_map(fn($a) => $a->id, $anmeldungen);
            $this->statusService->markMultipleAsExported($ids);
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

            // Get field types metadata if available
            $fieldTypes = $anmeldung->data['_fieldTypes'] ?? [];

            foreach ($anmeldung->data as $key => $value) {
                // Skip internal metadata fields (e.g., _fieldTypes)
                if (str_starts_with($key, '_')) {
                    continue;
                }

                // Skip file upload fields (they contain base64 data)
                if ($this->isFileUploadField($key, $fieldTypes, $value)) {
                    continue;
                }

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
     * Check if a field is a file upload field
     *
     * @param string $fieldName
     * @param array $fieldTypes _fieldTypes metadata from survey
     * @param mixed $value Field value
     * @return bool
     */
    private function isFileUploadField(string $fieldName, array $fieldTypes, mixed $value): bool
    {
        // Check via _fieldTypes metadata (most reliable)
        if (isset($fieldTypes[$fieldName])) {
            $type = is_array($fieldTypes[$fieldName])
                ? ($fieldTypes[$fieldName]['type'] ?? null)
                : $fieldTypes[$fieldName];

            if ($type === 'file') {
                return true;
            }
        }

        // Fallback: Heuristic detection of base64 data
        if (is_string($value)) {
            // Check for data URI scheme (data:image/png;base64,...)
            if (preg_match('/^data:[^;]+;base64,/', $value)) {
                return true;
            }

            // Check for very long strings that look like base64
            // (likely inline file data even without data URI)
            if (strlen($value) > 1000 && preg_match('/^[A-Za-z0-9+\/=]+$/', $value)) {
                return true;
            }
        }

        // Check for array of file objects (alternative upload format)
        if (is_array($value) && !empty($value)) {
            $firstItem = reset($value);
            if (is_array($firstItem) && isset($firstItem['content']) && isset($firstItem['name'])) {
                return true;
            }
        }

        return false;
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

        // Safety check: Detect and handle base64/upload data that slipped through
        if (is_string($value)) {
            // Check for data URI (should have been filtered already)
            if (preg_match('/^data:[^;]+;base64,/', $value)) {
                return '[Datei-Upload]';
            }

            // Check for very long strings (likely base64 data)
            if (strlen($value) > 1000 && preg_match('/^[A-Za-z0-9+\/=]+$/', $value)) {
                return '[Datei-Upload]';
            }

            // Check if value looks like an ISO date (YYYY-MM-DD or YYYY-MM-DD HH:MM:SS)
            if (preg_match('/^\d{4}-\d{2}-\d{2}/', $value)) {
                try {
                    $date = new \DateTimeImmutable($value);
                    // Format as German date (dd.mm.yyyy)
                    return $date->format('d.m.Y');
                } catch (\Exception $e) {
                    // Not a valid date, return as-is
                    return $value;
                }
            }

            return $value;
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
     * Get export data for a single Anmeldung by ID
     *
     * @return array{
     *   rows: Anmeldung[],
     *   columns: string[],
     *   metadata: array
     * }
     * @throws \InvalidArgumentException
     */
    public function getExportDataById(int $id): array
    {
        $anmeldung = $this->repository->findById($id);

        if ($anmeldung === null) {
            throw new \InvalidArgumentException('Eintrag nicht gefunden');
        }

        $anmeldungen = [$anmeldung];
        $columns = $this->extractColumns($anmeldungen);
        sort($columns);

        return [
            'rows' => $anmeldungen,
            'columns' => $columns,
            'metadata' => [
                'exportDate' => new \DateTimeImmutable(),
                'filter' => $anmeldung->formular,
                'totalRows' => 1,
                'singleExport' => true
            ]
        ];
    }

    /**
     * Generate filename for export
     */
    public function generateFilename(?string $formularFilter = null, ?int $id = null): string
    {
        $timestamp = date('Y-m-d_H-i');

        // Single record export
        if ($id !== null) {
            return "anmeldung_{$id}_{$timestamp}.xlsx";
        }

        if ($formularFilter !== null && $formularFilter !== '') {
            // Sanitize form name for filename
            $sanitized = preg_replace('/[^a-zA-Z0-9_-]/', '_', $formularFilter);
            return "anmeldungen_{$sanitized}_{$timestamp}.xlsx";
        }

        return "anmeldungen_{$timestamp}.xlsx";
    }
}