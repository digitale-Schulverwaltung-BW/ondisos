<?php
// backend/public/api/upload.php

declare(strict_types=1);

require_once __DIR__ . '/../../inc/bootstrap.php';

use App\Config\Config;

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

    // Validate file size (10MB default)
    $maxSize = (int)(getenv('UPLOAD_MAX_SIZE') ?: 10485760);
    if ($file['size'] > $maxSize) {
        throw new RuntimeException('File too large (max ' . ($maxSize / 1048576) . 'MB)', 400);
    }

    // Validate file type using MIME type AND extension
    // Security: MIME type check prevents attackers from renaming malicious files
    // (e.g., evil.php.jpg would fail MIME check even if extension passes)

    // Define allowed MIME types and their corresponding extensions
    // NOTE: doc/docx excluded due to macro security risks
    $allowedMimeTypes = [
        'application/pdf' => ['pdf'],
        'image/jpeg' => ['jpg', 'jpeg'],
        'image/png' => ['png'],
        'image/gif' => ['gif'],
        'image/webp' => ['webp'],
        'image/svg+xml' => ['svg'],
        // Office documents excluded by default for security (can contain macros)
        // Uncomment if needed:
        // 'application/msword' => ['doc'],
        // 'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => ['docx'],
    ];

    // Check MIME type using finfo (reads actual file content, not just extension)
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    if ($finfo === false) {
        throw new RuntimeException('Failed to initialize file info', 500);
    }

    $mimeType = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);

    if ($mimeType === false) {
        throw new RuntimeException('Failed to detect file type', 400);
    }

    // Validate extension
    $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

    // Check if MIME type is allowed
    if (!isset($allowedMimeTypes[$mimeType])) {
        throw new RuntimeException('File type not allowed (MIME: ' . $mimeType . ')', 400);
    }

    // Check if extension matches the MIME type
    if (!in_array($extension, $allowedMimeTypes[$mimeType], true)) {
        throw new RuntimeException('File extension does not match MIME type', 400);
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