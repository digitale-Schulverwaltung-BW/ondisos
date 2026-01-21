<?php
// backend/public/api/submit.php

declare(strict_types=1);

// Skip auth check - this API is called from frontend and doesn't use session auth
define('SKIP_AUTH_CHECK', true);

require_once __DIR__ . '/../../inc/bootstrap.php';

use App\Repositories\AnmeldungRepository;
use App\Validators\AnmeldungValidator;
use App\Services\MessageService as M;
use App\Services\PdfTokenService;
use App\Services\RateLimiter;
use App\Config\FormConfig;

header('Content-Type: application/json; charset=utf-8');

// Allow CORS from frontend (configure as needed)
$allowedOrigins = getenv('ALLOWED_ORIGINS') 
    ? explode(',', getenv('ALLOWED_ORIGINS'))
    : ['http://localhost'];

$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if (in_array($origin, $allowedOrigins, true)) {
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Access-Control-Allow-Methods: POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type');
}

// Handle preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
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

    // Use IP + User-Agent for better identification
    $identifier = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
    $identifier .= ':' . substr(md5($userAgent), 0, 8);

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

    // Get JSON payload
    $input = file_get_contents('php://input');
    $payload = json_decode($input, true, 512, JSON_THROW_ON_ERROR);

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

    // Prepare for database
    $repository = new AnmeldungRepository();

    $insertData = [
        'formular' => $formKey,
        'formular_version' => $metadata['version'] ?? '1.0',
        'name' => $name,
        'email' => $email,
        'status' => 'neu',
        'data' => json_encode($data, JSON_UNESCAPED_UNICODE)
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

    // Check if PDF is enabled for this form
    if (FormConfig::exists($formKey)) {
        $formConfig = FormConfig::get($formKey);
        $pdfConfig = $formConfig['pdf'] ?? null;

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