<?php
// src/Controllers/DownloadController.php

declare(strict_types=1);

namespace App\Controllers;

use InvalidArgumentException;

class DownloadController
{
    private const UPLOAD_DIR = __DIR__ . '/../../uploads';
    private const ALLOWED_EXTENSIONS = ['pdf', 'jpg', 'jpeg', 'png', 'gif', 'doc', 'docx', 'xls', 'xlsx', 'txt'];

    /**
     * Handle file download request
     * 
     * @throws InvalidArgumentException
     */
    public function download(string $fileName): void
    {
        // Validate filename
        $this->validateFileName($fileName);

        // Build safe file path
        $filePath = $this->getFilePath($fileName);

        // Check if file exists
        if (!file_exists($filePath) || !is_file($filePath)) {
            throw new InvalidArgumentException('Datei nicht gefunden');
        }

        // Check file is within upload directory (prevent directory traversal)
        $realPath = realpath($filePath);
        $uploadDir = realpath(self::UPLOAD_DIR);
        
        if ($realPath === false || $uploadDir === false || !str_starts_with($realPath, $uploadDir)) {
            throw new InvalidArgumentException('Ungültiger Dateipfad');
        }

        // Get file info
        $fileSize = filesize($filePath);
        $extension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
        $mimeType = $this->getMimeType($extension);

        // Send headers
        header('Content-Type: ' . $mimeType);
        header('Content-Disposition: attachment; filename="' . $this->sanitizeFileName($fileName) . '"');
        header('Content-Length: ' . $fileSize);
        header('Cache-Control: no-cache, must-revalidate');
        header('Pragma: no-cache');
        header('Expires: 0');

        // Output file
        readfile($filePath);
        exit;
    }

    /**
     * Validate filename
     * 
     * @throws InvalidArgumentException
     */
    private function validateFileName(string $fileName): void
    {
        if (empty($fileName)) {
            throw new InvalidArgumentException('Kein Dateiname angegeben');
        }

        // Check for directory traversal attempts
        if (str_contains($fileName, '..') || str_contains($fileName, '/') || str_contains($fileName, '\\')) {
            throw new InvalidArgumentException('Ungültiger Dateiname');
        }

        // Check extension
        $extension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
        if (!in_array($extension, self::ALLOWED_EXTENSIONS, true)) {
            throw new InvalidArgumentException('Dateityp nicht erlaubt');
        }
    }

    /**
     * Get safe file path
     */
    private function getFilePath(string $fileName): string
    {
        return self::UPLOAD_DIR . '/' . basename($fileName);
    }

    /**
     * Get MIME type for extension
     */
    private function getMimeType(string $extension): string
    {
        return match($extension) {
            'pdf' => 'application/pdf',
            'jpg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'gif' => 'image/gif',
            'doc' => 'application/msword',
            'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'xls' => 'application/vnd.ms-excel',
            'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'txt' => 'text/plain',
            default => 'application/octet-stream'
        };
    }

    /**
     * Sanitize filename for download header
     */
    private function sanitizeFileName(string $fileName): string
    {
        // Remove non-ASCII characters
        $fileName = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $fileName);
        
        // Remove any remaining problematic characters
        $fileName = preg_replace('/[^a-zA-Z0-9._-]/', '_', $fileName);
        
        return $fileName;
    }
}