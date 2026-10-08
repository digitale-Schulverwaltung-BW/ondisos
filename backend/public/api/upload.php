<?php
// backend/public/api/upload.php

declare(strict_types=1);

// Skip auth check - this API is called from frontend and doesn't use session auth
define('SKIP_AUTH_CHECK', true);

// Mark as API request so bootstrap.php resolves tenant from ?tenant=<slug>
define('API_REQUEST', true);

require_once __DIR__ . '/../../inc/bootstrap.php';

use App\Config\Config;
use App\Config\TenantContext;
use App\Repositories\AnmeldungRepository;
use App\Repositories\TenantRepository;
use App\Services\SignatureVerifier;
use App\Validators\AnmeldungValidator;
use App\Services\AuditLogger;
use App\Services\VirusScanService;
use App\Utils\FilenameSanitizer;
use App\Utils\ClientIdentity;
use App\Services\RateLimiter;
use App\Services\UploadPolicy;
use App\Services\FormDeliveryService;
use App\Repositories\FormConfigRepository;
use App\Repositories\FormResourceRepository;

header('Content-Type: application/json; charset=utf-8');

// Per-tenant HMAC + CORS validation
// Must run before processing so that unauthorized requests never reach business logic.
try {
    $tenantId = TenantContext::getTenantId();
} catch (\RuntimeException $e) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$tenant = (new TenantRepository())->findById($tenantId);
if ($tenant === null || !(bool)$tenant['active']) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

// Per-tenant CORS (replaces the old global ALLOWED_ORIGINS block)
$requestOrigin = $_SERVER['HTTP_ORIGIN'] ?? '';
if (!empty($tenant['origin'])) {
    // Tenant has an explicit origin configured — only allow that origin
    if ($requestOrigin === $tenant['origin']) {
        header('Access-Control-Allow-Origin: ' . $requestOrigin);
        header('Access-Control-Allow-Methods: POST, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, X-Signature, X-Timestamp, X-Nonce, X-Ondisos-Client');
    }
} else {
    // Tenant origin is NULL — fall back to global ALLOWED_ORIGINS
    $allowedOrigins = getenv('ALLOWED_ORIGINS')
        ? explode(',', getenv('ALLOWED_ORIGINS'))
        : ['http://localhost'];
    if (in_array($requestOrigin, $allowedOrigins, true)) {
        header('Access-Control-Allow-Origin: ' . $requestOrigin);
        header('Access-Control-Allow-Methods: POST, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, X-Signature, X-Timestamp, X-Nonce, X-Ondisos-Client');
    }
}

// HMAC validation — sign over canonical string (not multipart body)
// Canonical string: "{anmeldung_id}:{fieldname}:{original_filename}"
$anmeldungIdStr   = $_POST['anmeldung_id'] ?? '';
$fieldname        = $_POST['fieldname'] ?? '';
$originalFilename = $_FILES['file']['name'] ?? '';
$uploadTmpPath    = (($_FILES['file']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) ? ($_FILES['file']['tmp_name'] ?? null) : null;
$signatureCheck   = SignatureVerifier::fromEnv((string)$tenant['api_secret'], $tenantId)
    ->verifyUpload((string)$anmeldungIdStr, (string)$fieldname, (string)$originalFilename, $uploadTmpPath, $_SERVER);
if (!$signatureCheck['ok']) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

// Rate limiting per visitor (after the signature check, like submit.php)
if (filter_var($_ENV['RATE_LIMIT_ENABLED'] ?? 'true', FILTER_VALIDATE_BOOLEAN)) {
    $uploadLimiter = new RateLimiter(
        __DIR__ . '/../../cache/ratelimit-upload',
        (int)($_ENV['UPLOAD_RATE_LIMIT_MAX'] ?? 60),
        (int)($_ENV['UPLOAD_RATE_LIMIT_WINDOW'] ?? 60)
    );
    $uploadIdentifier = ClientIdentity::fingerprint($_SERVER, $tenantId);
    if (!$uploadLimiter->isAllowed($uploadIdentifier)) {
        $retryAfter = $uploadLimiter->getRetryAfter($uploadIdentifier);
        header('Retry-After: ' . $retryAfter);
        http_response_code(429);
        echo json_encode(['error' => 'Too many requests. Please try again later.', 'retry_after' => $retryAfter]);
        exit;
    }
}

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new RuntimeException('Invalid request method', 405);
    }

    // Validate anmeldung_id
    $anmeldungId = (int)$anmeldungIdStr;
    if ($anmeldungId <= 0) {
        throw new RuntimeException('Invalid anmeldung_id', 400);
    }

    // Validate fieldname (already read above for HMAC; re-use)
    if (empty($fieldname)) {
        throw new RuntimeException('Missing fieldname', 400);
    }

    // The entry must exist and belong to the authenticated tenant. The HMAC only proves
    // the caller holds this tenant's secret, not that anmeldung_id is theirs. findById()
    // is tenant-scoped and logs an IDOR attempt for foreign ids.
    $anmeldung = (new AnmeldungRepository())->findById($anmeldungId);
    if ($anmeldung === null) {
        throw new RuntimeException('Anmeldung nicht gefunden', 404);
    }

    // The field must be a file question of the published survey: the frontend relays whatever
    // the browser posted. (Forms that still use a survey file in the frontend have no survey here; the
    // limits per Anmeldung below apply regardless.)
    $bundle = (new FormDeliveryService(new FormConfigRepository(), new FormResourceRepository()))
        ->bundle($anmeldung->formular);
    if ($bundle !== null && $bundle['survey_json'] !== null) {
        $survey = json_decode($bundle['survey_json'], true);
        if (is_array($survey) && !UploadPolicy::isFileField($survey, (string)$fieldname)) {
            AuditLogger::uploadRejected($anmeldungId, (string)$originalFilename, 'unknown field ' . $fieldname);
            throw new RuntimeException('Ungültiges Upload-Feld', 400);
        }
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

    // Upload directory — scoped to the current tenant
    $tenantId  = TenantContext::getTenantId();
    $uploadsRoot = realpath(__DIR__ . '/../../uploads');
    if ($uploadsRoot === false) {
        throw new RuntimeException('Upload-Verzeichnis nicht verfügbar.', 500);
    }
    $uploadDir = $uploadsRoot . '/tenant-' . $tenantId;
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }

    // Generate safe filename: {anmeldung_id}_{sanitized_name}.{validated_ext}
    $originalName = basename($file['name']);
    $nameWithoutExt = FilenameSanitizer::sanitizeStem(pathinfo($originalName, PATHINFO_FILENAME));

    // Force the validated extension (prevents double extension attacks like evil.php.jpg)
    $safeFilename = $anmeldungId . '_' . $nameWithoutExt . '.' . $extension;
    $targetPath = $uploadDir . '/' . $safeFilename;

    // Limits per Anmeldung (a signature does not bound how many requests the browser can trigger)
    // (overwriting a file of the same name adds nothing)
    $existing = array_values(array_filter(
        glob($uploadDir . '/' . $anmeldungId . '_*') ?: [],
        fn(string $f): bool => is_file($f) && $f !== $targetPath
    ));
    $existingBytes = array_sum(array_map('filesize', $existing));
    $capError = UploadPolicy::capError(
        count($existing),
        (int)$existingBytes,
        (int)$file['size'],
        (int)($_ENV['UPLOAD_MAX_FILES_PER_ANMELDUNG'] ?? 20),
        (int)($_ENV['UPLOAD_MAX_BYTES_PER_ANMELDUNG'] ?? 52428800)
    );
    if ($capError !== null) {
        AuditLogger::uploadRejected($anmeldungId, (string)$originalFilename, 'cap: ' . $capError);
        throw new RuntimeException($capError, 429);
    }

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
