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

        return [
            'anmeldung' => $anmeldung,
            'structuredData' => $structuredData,
            'uploadedFiles' => $uploadedFiles
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

        $structured = [];

        foreach ($anmeldung->data as $key => $value) {
            $structured[] = [
                'key' => $key,
                'label' => $this->humanizeKey($key),
                'value' => $value,
                'type' => $this->detectValueType($value),
                'isFile' => $this->isFileReference($key, $value)
            ];
        }

        return $structured;
    }

    /**
     * Convert camelCase or snake_case to readable label
     */
    private function humanizeKey(string $key): string
    {
        // Convert snake_case to spaces
        $label = str_replace('_', ' ', $key);
        
        // Convert camelCase to spaces
        $label = preg_replace('/([a-z])([A-Z])/', '$1 $2', $label);
        
        // Capitalize first letter of each word
        return ucwords($label);
    }

    /**
     * Detect the type of value for better rendering
     */
    private function detectValueType(mixed $value): string
    {
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
     * Check if key/value might be a file reference
     */
    private function isFileReference(string $key, mixed $value): bool
    {
        if (!is_string($value)) {
            return false;
        }

        // Check common file field names
        $fileKeywords = ['file', 'upload', 'document', 'attachment', 'foto', 'bild', 'image'];
        
        foreach ($fileKeywords as $keyword) {
            if (stripos($key, $keyword) !== false) {
                return true;
            }
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
     * Find uploaded files for this Anmeldung
     */
    private function findUploadedFiles(Anmeldung $anmeldung): array
    {
        $uploadDir = __DIR__ . '/../../uploads';
        $files = [];

        if (!is_dir($uploadDir)) {
            return $files;
        }

        // Look for files matching this submission ID
        $pattern = $uploadDir . '/' . $anmeldung->id . '_*';
        $foundFiles = glob($pattern);

        foreach ($foundFiles as $filePath) {
            $fileName = basename($filePath);
            $fileSize = filesize($filePath);
            
            $files[] = [
                'name' => $fileName,
                'path' => $filePath,
                'size' => $fileSize,
                'sizeFormatted' => $this->formatFileSize($fileSize),
                'extension' => pathinfo($fileName, PATHINFO_EXTENSION),
                'downloadUrl' => 'download.php?file=' . urlencode($fileName)
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