<?php
// backend/public/api/forms.php
//
// Signed status endpoint (3.1): which forms does a tenant have? Used by the WordPress plugin's connection status.
//
// GET /api/forms.php?tenant=<slug>      Header: X-Signature = HMAC-SHA256("forms:<slug>", tenant api_secret)
// → {"success":true,"count":2,"forms":["bs","vabo"]}
//
// Unlike form-config.php this is NOT readable by anyone who knows the slug: only the holder of the tenant secret
// (the tenant's own frontend) can ask, and it only ever returns the tenant's own form keys.

declare(strict_types=1);

define('SKIP_AUTH_CHECK', true);
define('API_REQUEST', true);

require_once __DIR__ . '/../../inc/bootstrap.php';

use App\Config\TenantContext;
use App\Repositories\FormConfigRepository;
use App\Repositories\FormResourceRepository;
use App\Repositories\TenantRepository;
use App\Services\FormDeliveryService;
use App\Services\HmacValidator;
use App\Services\RateLimiter;

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

try {
    $tenantId = TenantContext::getTenantId();
} catch (\RuntimeException $e) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$tenant = (new TenantRepository())->findById($tenantId);
if ($tenant === null || !(bool)$tenant['active'] || (string)($tenant['slug'] ?? '') === '') {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

// Same answer for "wrong secret", "no signature" and "placeholder secret": nothing to learn from the difference.
$validator = new HmacValidator((string)$tenant['api_secret']);
if (!$validator->validateMessage('forms:' . $tenant['slug'], (string)($_SERVER['HTTP_X_SIGNATURE'] ?? ''))) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

if (filter_var($_ENV['RATE_LIMIT_ENABLED'] ?? 'true', FILTER_VALIDATE_BOOLEAN)) {
    $limiter    = new RateLimiter(__DIR__ . '/../../cache/ratelimit', (int)($_ENV['RATE_LIMIT_MAX'] ?? 10) * 6, (int)($_ENV['RATE_LIMIT_WINDOW'] ?? 60));
    $identifier = 'forms:' . RateLimiter::generateFingerprint($_SERVER);
    if (!$limiter->isAllowed($identifier)) {
        header('Retry-After: ' . $limiter->getRetryAfter($identifier));
        http_response_code(429);
        echo json_encode(['error' => 'Too many requests']);
        exit;
    }
}

$keys = (new FormDeliveryService(new FormConfigRepository(), new FormResourceRepository()))->formKeys();

echo json_encode(['success' => true, 'count' => count($keys), 'forms' => $keys], JSON_UNESCAPED_UNICODE);
