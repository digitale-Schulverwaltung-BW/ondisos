<?php
// backend/public/api/upload.php

declare(strict_types=1);

require_once __DIR__ . '/../../inc/bootstrap.php';

use App\Config\Config;
use App\Validators\AnmeldungValidator;
use App\Services\AuditLogger;
use App\Services\VirusScanService;

header('Content-Type: application/json; charset=utf-8');

// CORS
$allowedOrigins = getenv('ALLOWED_ORIGINS') 
    ? explode(',', getenv('ALLOWED_ORIGINS'))
    : ['http://localhost'];

$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if (in_array($origin, $allowedOrigins, true)) {
    header('Access-Control-Allow-Origin: ' . $origin);
}

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new RuntimeException('Invalid request method', 405);
    }

    // Validate anmeldung_id
    $anmeldungId = (int)($_POST['anmeldung_id'] ?? 0);
    if ($anmeldungId <= 0) {
        throw new RuntimeException('Invalid anmeldung_id', 400);
    }

    // Validate fieldname
    $fieldname = $_POST['fieldname'] ?? '';
    if (empty($fieldname)) {
        throw new RuntimeException('Missing fieldname', 400);
    }

    // Check if file was uploaded
    if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
        $error = $_FILES['file']['error'] ?? 'unknown';
        throw new RuntimeException('File upload failed: ' . $error, 400);
    }

    $file = $_FILES['file'];

    // Validate file using AnmeldungValidator
    // This performs comprehensive security checks:
    // - File size validation
    // - MIME type detection (content-based, not just extension)
    // - Extension validation (must match MIME type)
    // - Prevents disguised malicious files (e.g., evil.php.jpg)
    $validator = new AnmeldungValidator();
    $validationResult = $validator->validateFile($file);

    if (!$validationResult['success']) {
        throw new RuntimeException($validationResult['error'], 400);
    }

    // Extract validated MIME type and extension
    $mimeType = $validationResult['mime_type'];
    $extension = $validationResult['extension'];

    // Virus scan (ClamAV) — scans the tmp file before it is stored
    $virusScanEnabled = filter_var($_ENV['VIRUS_SCAN_ENABLED'] ?? 'false', FILTER_VALIDATE_BOOLEAN);
    if ($virusScanEnabled) {
        $scanner = VirusScanService::fromEnv();
        $scanResult = $scanner->scanFile($file['tmp_name']);

        if ($scanResult['clean'] === false) {
            // Virus found — reject upload
            AuditLogger::virusFound($anmeldungId, $file['name'], $scanResult['virus'] ?? 'unknown');
            throw new RuntimeException('Datei wurde als schädlich eingestuft und wurde abgelehnt.', 400);
        }

        if ($scanResult['clean'] === null) {
            // ClamAV unavailable — check strict mode
            $strict = filter_var($_ENV['VIRUS_SCAN_STRICT'] ?? 'false', FILTER_VALIDATE_BOOLEAN);
            error_log('VirusScan error: ' . ($scanResult['error'] ?? 'unknown'));
            if ($strict) {
                throw new RuntimeException('Virus-Scan nicht verfügbar. Upload abgelehnt.', 503);
            }
            // Soft fail: continue upload with warning in log
        }
    }

    // Upload directory
    $uploadDir = __DIR__ . '/../../uploads';
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }

    // Generate safe filename: {anmeldung_id}_{original_name}
    // 1. Use basename() to strip any path components
    $originalName = basename($file['name']);

    // 2. Validate filename contains only safe characters (no dots in middle to prevent double extensions)
    $nameWithoutExt = pathinfo($originalName, PATHINFO_FILENAME);
    if (!preg_match('/^[a-zA-Z0-9_-]+$/', $nameWithoutExt)) {
        throw new RuntimeException('Invalid filename. Only letters, numbers, underscore and hyphen allowed.', 400);
    }

    // 3. Force the validated extension (prevents double extension attacks like evil.php.jpg)
    $safeFilename = $anmeldungId . '_' . $nameWithoutExt . '.' . $extension;
    $targetPath = $uploadDir . '/' . $safeFilename;

    // Move uploaded file
    if (!move_uploaded_file($file['tmp_name'], $targetPath)) {
        throw new RuntimeException('Failed to move uploaded file', 500);
    }

    // Set permissions
    chmod($targetPath, 0644);

    // Log upload
    error_log(sprintf(
        'File uploaded: Anmeldung=%d, Field=%s, File=%s, Size=%d',
        $anmeldungId,
        $fieldname,
        $safeFilename,
        $file['size']
    ));
    AuditLogger::uploadSuccess($anmeldungId, $safeFilename);

    // Return success
    echo json_encode([
        'success' => true,
        'filename' => $safeFilename,
        'size' => $file['size']
    ]);

} catch (RuntimeException $e) {
    http_response_code($e->getCode() ?: 400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);

} catch (Throwable $e) {
    error_log('Unexpected error in upload.php: ' . $e->getMessage());

    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Upload failed'
    ]);
}