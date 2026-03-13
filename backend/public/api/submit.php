<?php
// backend/public/api/submit.php

declare(strict_types=1);

// Skip auth check - this API is called from frontend and doesn't use session auth
define('SKIP_AUTH_CHECK', true);

// Mark as API request so bootstrap.php resolves tenant from ?tenant=<slug>
define('API_REQUEST', true);

require_once __DIR__ . '/../../inc/bootstrap.php';

use App\Repositories\AnmeldungRepository;
use App\Repositories\TenantRepository;
use App\Validators\AnmeldungValidator;
use App\Services\MessageService as M;
use App\Services\HmacValidator;
use App\Services\PdfTokenService;
use App\Services\RateLimiter;
use App\Config\FormConfig;
use App\Config\TenantContext;

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
        header('Access-Control-Allow-Headers: Content-Type, X-Signature');
    }
} else {
    // Tenant origin is NULL — fall back to global ALLOWED_ORIGINS
    $allowedOrigins = getenv('ALLOWED_ORIGINS')
        ? explode(',', getenv('ALLOWED_ORIGINS'))
        : ['http://localhost'];
    if (in_array($requestOrigin, $allowedOrigins, true)) {
        header('Access-Control-Allow-Origin: ' . $requestOrigin);
        header('Access-Control-Allow-Methods: POST, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, X-Signature');
    }
}

// Handle preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// HMAC validation — sign over raw request body
$body        = file_get_contents('php://input');
$providedSig = $_SERVER['HTTP_X_SIGNATURE'] ?? '';
$hmacValidator = new HmacValidator($tenant['api_secret']);
if (!$hmacValidator->validate($body, $providedSig)) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

// Rate Limiting (if enabled)
$rateLimitEnabled = filter_var($_ENV['RATE_LIMIT_ENABLED'] ?? 'true', FILTER_VALIDATE_BOOLEAN);
if ($rateLimitEnabled) {
    $rateLimitMax = (int)($_ENV['RATE_LIMIT_MAX'] ?? 10);
    $rateLimitWindow = (int)($_ENV['RATE_LIMIT_WINDOW'] ?? 60);

    $rateLimiter = new RateLimiter(
        __DIR__ . '/../../cache/ratelimit',
        $rateLimitMax,
        $rateLimitWindow
    );

    // Use robust fingerprinting (IP + hashed User-Agent + Accept-Language)
    // This prevents bypass via User-Agent rotation attacks
    $identifier = RateLimiter::generateFingerprint($_SERVER);

    if (!$rateLimiter->isAllowed($identifier)) {
        $retryAfter = $rateLimiter->getRetryAfter($identifier);
        header('Retry-After: ' . $retryAfter);
        http_response_code(429);
        echo json_encode([
            'error' => M::get('api.errors.rate_limit', 'Too many requests. Please try again later.'),
            'retry_after' => $retryAfter,
        ]);
        exit;
    }
}

try {
    // Validate request method
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new RuntimeException(M::get('api.errors.invalid_method', 'Invalid request method'), 405);
    }

    // Get JSON payload (body was already read above for HMAC; re-use it)
    $payload = json_decode($body, true, 512, JSON_THROW_ON_ERROR);

    if (!is_array($payload)) {
        throw new RuntimeException(M::get('errors.invalid_json'), 400);
    }

    // Extract data
    $formKey = $payload['form_key'] ?? '';
    $data = $payload['data'] ?? [];
    $metadata = $payload['metadata'] ?? [];

    if (empty($formKey)) {
        throw new RuntimeException(M::get('api.errors.missing_form_key', 'Missing form_key'), 400);
    }

    if (empty($data) || !is_array($data)) {
        throw new RuntimeException(M::get('errors.invalid_data'), 400);
    }

    // Extract common fields
    $name = $payload['name'] ?? $data['Name'] ?? $data['name'] ?? null;
    $email = $payload['email'] ?? $data['email'] ?? $data['email1'] ?? $data['Email'] ?? $data['E-mail'] ?? $data['E-Mail'] ?? null;

    // Validate
    $validator = new AnmeldungValidator();
    $validationData = [
        'formular' => $formKey,
        'name' => $name,
        'email' => $email
    ];

    if (!$validator->validate($validationData)) {
        throw new RuntimeException(
            M::format('api.errors.validation_failed', ['error' => $validator->getFirstError()]),
            400
        );
    }

    // Check if PDF is enabled for this form
    // Frontend sends PDF config as part of payload (single source of truth)
    // Fall back to FormConfig for backwards compatibility with old frontends
    $pdfConfig = $payload['pdf_config'] ?? null;

    if ($pdfConfig === null && FormConfig::exists($formKey)) {
        // Backwards compatibility: load from backend FormConfig if not in payload
        $formConfig = FormConfig::get($formKey);
        $pdfConfig = $formConfig['pdf'] ?? null;
    }

    // Prepare for database
    $repository = new AnmeldungRepository();

    $insertData = [
        'formular' => $formKey,
        'formular_version' => $metadata['version'] ?? '1.0',
        'name' => $name,
        'email' => $email,
        'status' => 'neu',
        'data' => json_encode($data, JSON_UNESCAPED_UNICODE),
        // Persist pdf_config so download.php can load it without a local forms-config.php
        'pdf_config' => $pdfConfig !== null ? json_encode($pdfConfig, JSON_UNESCAPED_UNICODE) : null,
    ];

    // Insert into database
    $id = $repository->insert($insertData);

    if (!$id) {
        throw new RuntimeException(M::get('api.errors.save_failed', 'Failed to save anmeldung'), 500);
    }

    // Log successful submission
    error_log(sprintf(
        'New anmeldung submitted: ID=%d, Form=%s, Email=%s',
        $id,
        $formKey,
        $email ?? 'none'
    ));

    // Prepare response
    $response = [
        'success' => true,
        'id' => $id
    ];

    if ($pdfConfig && ($pdfConfig['enabled'] ?? false)) {
            try {
                // Generate PDF token
                $tokenService = new PdfTokenService();
                $lifetime = $pdfConfig['token_lifetime'] ?? PdfTokenService::getDefaultLifetime();
                $token = $tokenService->generateToken($id, $lifetime);

                // Add PDF download info to response
                // Note: URL points to frontend proxy (not backend directly)
                // Frontend is publicly accessible, backend is intranet-only
                // Relative URL works for both root and subdirectory installations
                $response['pdf_download'] = [
                    'enabled' => true,
                    'required' => $pdfConfig['required'] ?? false,
                    'url' => 'pdf/download.php?token=' . $token,
                    'title' => $pdfConfig['download_title'] ?? 'Bestätigung herunterladen',
                    'expires_in' => $lifetime
                ];
            } catch (\Throwable $e) {
                // If token generation fails, log but don't fail the whole request
                error_log('PDF token generation failed: ' . $e->getMessage());
            }
        }

    // Return success
    http_response_code(201);
    echo json_encode($response);

} catch (JsonException $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => M::get('errors.invalid_json')
    ]);

} catch (RuntimeException $e) {
    http_response_code($e->getCode() ?: 400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);

} catch (Throwable $e) {
    error_log('Unexpected error in submit.php: ' . $e->getMessage());
    error_log('Stack trace: ' . $e->getTraceAsString());

    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => M::get('api.errors.internal_server_error', 'Internal server error')
    ]);
}
