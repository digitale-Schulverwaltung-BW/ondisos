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

    // Validate file type
    $allowedTypes = getenv('UPLOAD_ALLOWED_TYPES') 
        ? explode(',', getenv('UPLOAD_ALLOWED_TYPES'))
        : ['pdf', 'jpg', 'jpeg', 'png', 'gif', 'doc', 'docx'];

    $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    
    if (!in_array($extension, $allowedTypes, true)) {
        throw new RuntimeException('File type not allowed: ' . $extension, 400);
    }

    // Upload directory
    $uploadDir = __DIR__ . '/../../uploads';
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }

    // Generate safe filename: {anmeldung_id}_{original_name}
    $safeFilename = $anmeldungId . '_' . preg_replace('/[^a-zA-Z0-9._-]/', '_', $file['name']);
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