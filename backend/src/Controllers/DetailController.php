<?php
// src/Controllers/DetailController.php

declare(strict_types=1);

namespace App\Controllers;

use App\Repositories\AnmeldungRepository;
use App\Models\Anmeldung;
use InvalidArgumentException;

class DetailController
{
    public function __construct(
        private AnmeldungRepository $repository
    ) {}

    /**
     * Handle detail page request
     * 
     * @throws InvalidArgumentException
     */
    public function show(int $id): array
    {
        if ($id <= 0) {
            throw new InvalidArgumentException('Ungültige ID');
        }

        $anmeldung = $this->repository->findById($id);

        if ($anmeldung === null) {
            throw new InvalidArgumentException('Eintrag nicht gefunden');
        }

        // Parse and structure the data
        $structuredData = $this->structureData($anmeldung);

        // Find uploaded files
        $uploadedFiles = $this->findUploadedFiles($anmeldung);

        // Navigation: prev/next within the same formular
        $adjacent = $this->repository->findAdjacentIds($id, $anmeldung->formular);

        return [
            'anmeldung' => $anmeldung,
            'structuredData' => $structuredData,
            'uploadedFiles' => $uploadedFiles,
            'prevId' => $adjacent['prev'],
            'nextId' => $adjacent['next'],
        ];
    }

    /**
     * Structure data for display
     */
    private function structureData(Anmeldung $anmeldung): array
    {
        if ($anmeldung->data === null || empty($anmeldung->data)) {
            return [];
        }

        // Extract field types metadata if present
        $fieldTypes = $anmeldung->data['_fieldTypes'] ?? [];

        $structured = [];

        foreach ($anmeldung->data as $key => $value) {
            // Skip internal metadata fields
            if (str_starts_with($key, '_')) {
                continue;
            }

            // Skip unresolved autofill sentinels
            if ($value === '_autofill') {
                continue;
            }

            $storedType = $fieldTypes[$key] ?? null;

            $structured[] = [
                'key' => $key,
                'label' => $this->humanizeKey($key),
                'value' => $value,
                'type' => $this->detectValueType($value, $storedType),
                'isFile' => $this->isFileField($key, $value, $storedType)
            ];
        }

        return $structured;
    }

    /**
     * Convert camelCase or snake_case to readable label
     *
     * Security: Sanitizes key to prevent XSS attacks.
     * Even though the view should also escape, defense-in-depth is important.
     */
    private function humanizeKey(string $key): string
    {
        // Sanitize input to prevent XSS (defense-in-depth)
        $key = htmlspecialchars($key, ENT_QUOTES, 'UTF-8');

        // Return field name as-is for downstream tool compatibility
        return $key;

        /* Original humanization logic (disabled for field name compatibility):
        // Convert snake_case to spaces
        $label = str_replace('_', ' ', $key);

        // Convert camelCase to spaces
        $label = preg_replace('/([a-z])([A-Z])/', '$1 $2', $label);

        // Capitalize first letter of each word
        return ucwords($label);
        */
    }

    /**
     * Detect the type of value for better rendering
     * Uses stored SurveyJS type info if available, falls back to heuristics
     */
    private function detectValueType(mixed $value, ?array $storedType = null): string
    {
        // Use stored type info from SurveyJS if available
        if ($storedType !== null) {
            $type = $storedType['type'] ?? null;
            $inputType = $storedType['inputType'] ?? null;

            // Map SurveyJS inputType to display type
            if ($inputType === 'date') {
                return 'date';
            }
            if ($inputType === 'email') {
                return 'email';
            }
            if ($inputType === 'url') {
                return 'url';
            }

            // Map SurveyJS type to display type
            if ($type === 'boolean') {
                return 'boolean';
            }
            if ($type === 'checkbox' || $type === 'tagbox') {
                return 'array';
            }
        }

        // Fall back to heuristics for backwards compatibility
        if (is_array($value)) {
            return 'array';
        }

        if (is_bool($value)) {
            return 'boolean';
        }

        if (is_numeric($value)) {
            return 'number';
        }

        // Check if it's a URL
        if (is_string($value) && filter_var($value, FILTER_VALIDATE_URL)) {
            return 'url';
        }

        // Check if it's an email
        if (is_string($value) && filter_var($value, FILTER_VALIDATE_EMAIL)) {
            return 'email';
        }

        // Check if it's a date
        if (is_string($value) && strtotime($value) !== false && preg_match('/^\d{4}-\d{2}-\d{2}/', $value)) {
            return 'date';
        }

        return 'string';
    }

    /**
     * Check if field is a file field
     * Uses stored SurveyJS type if available, falls back to heuristics
     */
    private function isFileField(string $key, mixed $value, ?array $storedType = null): bool
    {
        // Use stored type from SurveyJS if available
        if ($storedType !== null) {
            return ($storedType['type'] ?? '') === 'file';
        }

        // Fall back to heuristics for backwards compatibility
        if (!is_string($value)) {
            return false;
        }

        if ($this->keyLooksLikeFileField($key)) {
            return true;
        }

        // Check file extensions
        $extensions = ['pdf', 'jpg', 'jpeg', 'png', 'gif', 'doc', 'docx', 'xls', 'xlsx'];

        foreach ($extensions as $ext) {
            if (str_ends_with(strtolower($value), ".$ext")) {
                return true;
            }
        }

        return false;
    }

    /**
     * Heuristic on the field key (legacy submissions without stored SurveyJS type).
     *
     * The key is split into words (camelCase, "_", "-", spaces). A plain substring match
     * is wrong: 'bild' would hit "Ausbildungsbetrieb", "Ausbilder" or "Vorbildung".
     * - 'bild'/'bilder' count only as a whole word ("mein_bild", "Bilder")
     * - 'file', 'foto', 'image', ... count at the start of a word ("file_upload", "Fotografie")
     * - 'upload', 'attachment', 'document' also at the end of a word ("zeugnisUpload");
     *   'file' does not, otherwise "Profile" would match
     */
    private function keyLooksLikeFileField(string $key): bool
    {
        $spaced = preg_replace('/(?<=\p{Ll})(?=\p{Lu})/u', ' ', $key) ?? $key;
        $words = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($spaced), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        $wholeWords = ['bild', 'bilder'];
        $prefixes = ['file', 'upload', 'document', 'attachment', 'foto', 'image'];
        $suffixes = ['upload', 'attachment', 'document'];

        foreach ($words as $word) {
            if (in_array($word, $wholeWords, true)) {
                return true;
            }
            foreach ($prefixes as $prefix) {
                if (str_starts_with($word, $prefix)) {
                    return true;
                }
            }
            foreach ($suffixes as $suffix) {
                if (str_ends_with($word, $suffix)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Find uploaded files for this Anmeldung
     *
     * Files live in uploads/tenant-<id>/ (the tenant that owns the Anmeldung, which is
     * not necessarily the active context: platform admins can view "all tenants").
     */
    private function findUploadedFiles(Anmeldung $anmeldung): array
    {
        $files = [];

        // The Anmeldung was already loaded through the tenant-scoped findById(), so reading its tenant is safe.
        $tenantId = $this->repository->findTenantIdById($anmeldung->id);
        if ($tenantId === null) {
            return $files;
        }

        $uploadDir = __DIR__ . '/../../uploads/tenant-' . $tenantId;
        if (!is_dir($uploadDir)) {
            return $files;
        }

        // Look for files matching this submission ID
        $pattern = $uploadDir . '/' . $anmeldung->id . '_*';
        $foundFiles = glob($pattern) ?: [];

        foreach ($foundFiles as $filePath) {
            $fileName = basename($filePath);
            $fileSize = filesize($filePath);
            
            $files[] = [
                'name' => $fileName,
                'path' => $filePath,
                'size' => $fileSize,
                'sizeFormatted' => $this->formatFileSize($fileSize),
                'extension' => pathinfo($fileName, PATHINFO_EXTENSION),
                'downloadUrl' => 'download.php?file=' . urlencode($fileName) . '&mode=view'
            ];
        }

        return $files;
    }

    /**
     * Format file size in human-readable format
     */
    private function formatFileSize(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $unitIndex = 0;
        $size = $bytes;

        while ($size >= 1024 && $unitIndex < count($units) - 1) {
            $size /= 1024;
            $unitIndex++;
        }

        return round($size, 2) . ' ' . $units[$unitIndex];
    }
}