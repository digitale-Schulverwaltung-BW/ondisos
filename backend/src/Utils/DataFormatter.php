<?php
declare(strict_types=1);

namespace App\Utils;

use App\Services\MessageService as M;

/**
 * Data Formatter Utility
 *
 * Helper functions for formatting data for display in PDFs and emails.
 * Provides humanization, value formatting, and field filtering.
 */
class DataFormatter
{
    /**
     * Humanize a field key for display
     *
     * Converts: "user_name" → "User Name"
     *           "emailAddress" → "Email Address"
     *           "firma-name" → "Firma Name"
     *
     * @param string $key The field key
     * @return string Humanized key
     */
    public static function humanizeKey(string $key): string
    {
        // Replace underscores and hyphens with spaces
        $humanized = str_replace(['_', '-'], ' ', $key);

        // Convert camelCase to spaces (emailAddress → email Address)
        $humanized = preg_replace('/([a-z])([A-Z])/', '$1 $2', $humanized);

        // Capitalize first letter of each word
        $humanized = ucwords($humanized);

        return $humanized;
    }

    /**
     * Format a value for display
     *
     * - bool → "Ja" / "Nein"
     * - array → comma-separated list
     * - date (ISO format) → "dd.mm.yyyy"
     * - null/empty → "-"
     *
     * @param mixed $value The value to format
     * @return string Formatted value
     */
    public static function formatValue(mixed $value): string
    {
        // Handle null/empty
        if ($value === null || $value === '') {
            return '-';
        }

        // Handle boolean
        if (is_bool($value)) {
            return $value
                ? M::get('ui.detail.yes', 'Ja')
                : M::get('ui.detail.no', 'Nein');
        }

        // Handle array
        if (is_array($value)) {
            // Check if it's a file upload array (has 'content' or 'name' keys)
            if (isset($value[0]) && is_array($value[0])) {
                $firstItem = $value[0];
                if (isset($firstItem['content']) || isset($firstItem['name'])) {
                    // File upload - return file names
                    $fileNames = array_map(fn($file) => $file['name'] ?? 'Datei', $value);
                    return implode(', ', $fileNames);
                }
            }

            // Regular array - join with comma
            return implode(', ', array_map('strval', $value));
        }

        // Handle string - check if it's a date
        if (is_string($value) && self::isIsoDate($value)) {
            return self::formatDate($value);
        }

        // Default: return as string
        return (string)$value;
    }

    /**
     * Check if a string is an ISO date (YYYY-MM-DD)
     *
     * @param string $value The value to check
     * @return bool True if ISO date
     */
    public static function isIsoDate(string $value): bool
    {
        return (bool)preg_match('/^\d{4}-\d{2}-\d{2}$/', $value);
    }

    /**
     * Format an ISO date to German format
     *
     * Converts: "2000-01-31" → "31.01.2000"
     *
     * @param string $date ISO date string
     * @return string Formatted date or original string if invalid
     */
    public static function formatDate(string $date): string
    {
        try {
            $dt = new \DateTimeImmutable($date);
            return $dt->format('d.m.Y');
        } catch (\Exception $e) {
            return $date; // Return original if parsing fails
        }
    }

    /**
     * Filter fields based on include/exclude configuration
     *
     * @param array<string, mixed> $data The data to filter
     * @param string|array $includeFields 'all' or array of field names
     * @param array $excludeFields Array of field names to exclude
     * @return array<string, mixed> Filtered data
     */
    public static function filterFields(
        array $data,
        string|array $includeFields = 'all',
        array $excludeFields = []
    ): array {
        // Start with all fields if 'all', otherwise only included fields
        if ($includeFields === 'all') {
            $filtered = $data;
        } else {
            $filtered = array_filter(
                $data,
                fn($key) => in_array($key, $includeFields, true),
                ARRAY_FILTER_USE_KEY
            );
        }

        // Remove excluded fields
        if (!empty($excludeFields)) {
            $filtered = array_filter(
                $filtered,
                fn($key) => !in_array($key, $excludeFields, true),
                ARRAY_FILTER_USE_KEY
            );
        }

        // Remove internal fields (starting with _)
        $filtered = array_filter(
            $filtered,
            fn($key) => !str_starts_with($key, '_'),
            ARRAY_FILTER_USE_KEY
        );

        // Remove consent fields (DSGVO checkboxes)
        $filtered = array_filter(
            $filtered,
            fn($key) => !str_starts_with($key, 'consent_'),
            ARRAY_FILTER_USE_KEY
        );

        return $filtered;
    }

    /**
     * Sort fields based on their order in the original survey
     *
     * Uses _fieldTypes metadata if available to preserve survey order.
     * Falls back to alphabetical if metadata not available.
     *
     * @param array<string, mixed> $data The data to sort
     * @param array|null $fieldTypes Optional field types metadata from survey
     * @return array<string, mixed> Sorted data
     */
    public static function sortFieldsByOrder(array $data, ?array $fieldTypes = null): array
    {
        // If we have field types metadata, use that order
        if ($fieldTypes !== null && is_array($fieldTypes)) {
            $orderedData = [];

            // First, add fields in the order they appear in fieldTypes
            foreach (array_keys($fieldTypes) as $key) {
                if (array_key_exists($key, $data)) {
                    $orderedData[$key] = $data[$key];
                }
            }

            // Then add any remaining fields (shouldn't happen, but for safety)
            foreach ($data as $key => $value) {
                if (!array_key_exists($key, $orderedData)) {
                    $orderedData[$key] = $value;
                }
            }

            return $orderedData;
        }

        // Fallback: alphabetical sort
        ksort($data);
        return $data;
    }

    /**
     * Prepare data for PDF display
     *
     * Combines filtering and sorting in one step.
     *
     * @param array<string, mixed> $data The raw survey data
     * @param array $pdfConfig PDF configuration from forms-config
     * @return array<string, mixed> Filtered and sorted data
     */
    public static function prepareForPdf(array $data, array $pdfConfig): array
    {
        // Extract field types if available (stored in data by survey-handler.js)
        $fieldTypes = $data['_fieldTypes'] ?? null;

        // Filter fields
        $includeFields = $pdfConfig['include_fields'] ?? 'all';
        $excludeFields = $pdfConfig['exclude_fields'] ?? [];

        $filtered = self::filterFields($data, $includeFields, $excludeFields);

        // Sort by survey order
        $sorted = self::sortFieldsByOrder($filtered, $fieldTypes);

        return $sorted;
    }
}
