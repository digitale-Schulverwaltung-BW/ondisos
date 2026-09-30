<?php
// src/Controllers/DownloadController.php

declare(strict_types=1);

namespace App\Controllers;

use App\Config\TenantContext;
use InvalidArgumentException;

class DownloadController
{
    private const UPLOADS_BASE = __DIR__ . '/../../uploads';
    private const ALLOWED_EXTENSIONS = ['pdf', 'jpg', 'jpeg', 'png', 'gif', 'doc', 'docx', 'xls', 'xlsx', 'txt'];

    /**
     * Returns the tenant-scoped upload directory path.
     *
     * Public visibility is required so unit tests can call it directly
     * without an HTTP context.
     */
    public function getAllowedUploadDir(): string
    {
        $tenantId = TenantContext::getTenantId();
        return realpath(self::UPLOADS_BASE) . '/tenant-' . $tenantId;
    }

    /**
     * Returns true when $realPath is inside $allowedDir (inclusive).
     *
     * Public visibility is required so unit tests can exercise the path
     * validation logic without needing a real file on disk.
     *
     * @param string|false $realPath  The resolved real path of the requested file.
     * @param string       $allowedDir The tenant-scoped upload directory (already realpath-resolved).
     */
    public function isWithinAllowedDir(string|false $realPath, string $allowedDir): bool
    {
        if ($realPath === false) {
            return false;
        }
        return str_starts_with($realPath, $allowedDir . '/') || $realPath === $allowedDir;
    }

    /**
     * Handle file download request
     *
     * @param string $fileName The file to download/view
     * @param bool $inline If true, display inline (browser viewer); if false, force download
     * @throws InvalidArgumentException
     */
    public function download(string $fileName, bool $inline = false): void
    {
        // Validate filename
        $this->validateFileName($fileName);

        // Build safe file path within tenant directory
        $filePath = $this->getFilePath($fileName);

        // Check if file exists
        if (!file_exists($filePath) || !is_file($filePath)) {
            throw new InvalidArgumentException('Datei nicht gefunden');
        }

        // Check file is within tenant upload directory (prevent directory traversal
        // and cross-tenant access)
        $allowedDir = $this->getAllowedUploadDir();
        $realPath   = realpath($filePath);

        if (!$this->isWithinAllowedDir($realPath, $allowedDir)) {
            throw new InvalidArgumentException('Access denied: file is outside tenant upload directory.');
        }

        // Get file info
        $fileSize  = filesize($filePath);
        $extension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
        $mimeType  = $this->getMimeType($extension);

        // Send headers
        header('Content-Type: ' . $mimeType);

        // Inline for browser viewing (PDFs, images) or attachment for download
        $disposition = $inline ? 'inline' : 'attachment';
        header('Content-Disposition: ' . $disposition . '; filename="' . $this->sanitizeFileName($fileName) . '"');

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
     * Get safe file path within the tenant upload directory.
     */
    private function getFilePath(string $fileName): string
    {
        return $this->getAllowedUploadDir() . '/' . basename($fileName);
    }

    /**
     * Get MIME type for extension
     */
    private function getMimeType(string $extension): string
    {
        return match($extension) {
            'pdf'  => 'application/pdf',
            'jpg', 'jpeg' => 'image/jpeg',
            'png'  => 'image/png',
            'gif'  => 'image/gif',
            'doc'  => 'application/msword',
            'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'xls'  => 'application/vnd.ms-excel',
            'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'txt'  => 'text/plain',
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
