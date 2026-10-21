<?php
// backend/public/api/signature-handoff.php
//
// Signature on a smartphone via QR code (3.2.0a, docs/entwicklung/plans/PLAN-3.2.0a.md).
// Called only by the frontend server (server to server, no CORS); signature format 2 only.
//
// POST JSON {"action": "create"|"info"|"submit"|"poll", ...}
//   create: form_key, field_name, field_title, width, height, sign_url → id, read_key, qr_svg, sign_link, expires_in
//   info:   id, token (write token from the QR code) → school, form_key, field_title, width, height, expires_in
//   submit: id, token, png (data:image/png;base64,…) → ok
//   poll:   id, key (read key of the computer) → status (open|scanned|signed|expired), expires_in, png once
// Errors: {"ok": false, "error": "<code>"}; the frontend turns the code into a message.

declare(strict_types=1);

define('SKIP_AUTH_CHECK', true);
define('API_REQUEST', true);

require_once __DIR__ . '/../../inc/bootstrap.php';

use App\Config\FormConfig;
use App\Config\TenantContext;
use App\Repositories\SignatureHandoffRepository;
use App\Repositories\TenantRepository;
use App\Services\AuditLogger;
use App\Services\RateLimiter;
use App\Services\SignatureHandoffService;
use App\Services\SignatureVerifier;
use App\Utils\ClientIdentity;
use App\Utils\QrCodeSvg;

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

/** @param array<string, mixed> $body */
function handoffRespond(int $status, array $body): never
{
    http_response_code($status);
    echo json_encode($body, JSON_UNESCAPED_SLASHES);
    exit;
}

function handoffFail(int $status, string $error): never
{
    handoffRespond($status, ['ok' => false, 'error' => $error]);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    handoffFail(405, 'method');
}

try {
    $tenantId = TenantContext::getTenantId();
} catch (\RuntimeException $e) {
    handoffFail(401, 'unauthorized');
}

$tenant = (new TenantRepository())->findById($tenantId);
if ($tenant === null || !(bool)$tenant['active']) {
    handoffFail(401, 'unauthorized');
}

$body = (string)file_get_contents('php://input');
if (!SignatureVerifier::fromEnv((string)$tenant['api_secret'], $tenantId)->verifySignatureHandoff($body, $_SERVER)['ok']) {
    handoffFail(401, 'unauthorized');
}

try {
    $payload = json_decode($body, true, 16, JSON_THROW_ON_ERROR);
} catch (\JsonException $e) {
    handoffFail(400, SignatureHandoffService::ERROR_INVALID_INPUT);
}
if (!is_array($payload)) {
    handoffFail(400, SignatureHandoffService::ERROR_INVALID_INPUT);
}

$action = is_string($payload['action'] ?? null) ? $payload['action'] : '';
$str = static fn(string $key): string => is_string($payload[$key] ?? null) ? $payload[$key] : '';
$int = static fn(string $key): int => is_int($payload[$key] ?? null) ? $payload[$key] : 0;

// Rate limit per visitor (X-Ondisos-Client) with one bucket per kind of request:
// creating sessions is strict, polling every 3 s and the phone's requests are generous.
$limits = [
    'create' => [10, 600],
    'info'   => [30, 60],
    'submit' => [30, 60],
    'poll'   => [60, 60],
];
if (!isset($limits[$action])) {
    handoffFail(400, SignatureHandoffService::ERROR_INVALID_INPUT);
}
if (filter_var($_ENV['RATE_LIMIT_ENABLED'] ?? 'true', FILTER_VALIDATE_BOOLEAN)) {
    [$max, $window] = $limits[$action];
    $limiter = new RateLimiter(__DIR__ . '/../../cache/ratelimit', $max, $window);
    $identifier = 'signature-' . $action . ':' . ClientIdentity::fingerprint($_SERVER, $tenantId);
    if (!$limiter->isAllowed($identifier)) {
        header('Retry-After: ' . $limiter->getRetryAfter($identifier));
        handoffFail(429, 'rate_limit');
    }
}

$service = new SignatureHandoffService(new SignatureHandoffRepository());

$statusFor = static fn(string $error): int => match ($error) {
    SignatureHandoffService::ERROR_INVALID_INPUT => 400,
    SignatureHandoffService::ERROR_INVALID => 404,
    SignatureHandoffService::ERROR_USED => 409,
    SignatureHandoffService::ERROR_EXPIRED => 410,
    SignatureHandoffService::ERROR_BAD_IMAGE => 422,
    SignatureHandoffService::ERROR_LIMIT => 429,
    default => 400,
};

$result = match ($action) {
    'create' => (static function () use ($service, $str, $int): array {
        $formKey = $str('form_key');
        $config = FormConfig::get($formKey);
        if ($config === null || ($config['signature_handoff'] ?? false) !== true) {
            handoffFail(403, 'disabled');
        }
        $created = $service->create($formKey, $str('field_name'), $str('field_title'), $int('width'), $int('height'), $str('sign_url'));
        if (!$created['ok']) {
            return $created;
        }

        return [
            'ok' => true,
            'id' => $created['id'],
            'read_key' => $created['read_key'],
            'qr_svg' => QrCodeSvg::render($created['qr_url']),
            // Same link as text, for a computer user who cannot scan (the user holds both secrets anyway)
            'sign_link' => $created['qr_url'],
            'expires_in' => $created['expires_in'],
        ];
    })(),
    'info' => (static function () use ($service, $str, $tenant): array {
        $info = $service->info($str('id'), $str('token'));

        return $info['ok'] ? ['school' => (string)$tenant['name']] + $info : $info;
    })(),
    'submit' => $service->submit($str('id'), $str('token'), $str('png')),
    'poll' => $service->poll($str('id'), $str('key')),
};

if (!$result['ok']) {
    if ($result['error'] === SignatureHandoffService::ERROR_INVALID && $action !== 'create') {
        AuditLogger::signatureHandoffRejected($action, $result['error']);
    }
    handoffFail($statusFor($result['error']), $result['error']);
}

handoffRespond(200, $result);
