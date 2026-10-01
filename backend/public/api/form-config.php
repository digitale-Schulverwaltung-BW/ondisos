<?php
// backend/public/api/form-config.php

declare(strict_types=1);

// Skip auth check — this endpoint is called server-side by the frontend PHP (BackendApiClient),
// not directly from the browser. Tenant resolution via slug is sufficient.
define('SKIP_AUTH_CHECK', true);

// API_REQUEST causes bootstrap.php to resolve the tenant from ?tenant=<slug>
// and call TenantContext::initialize($tenantId) before we reach the code below.
define('API_REQUEST', true);

require_once __DIR__ . '/../../inc/bootstrap.php';

use App\Config\FormConfig;
use App\Config\TenantContext;
use App\Repositories\FormConfigRepository;
use App\Repositories\FormResourceRepository;
use App\Services\FormDeliveryService;

header('Content-Type: application/json; charset=utf-8');

// Validate that bootstrap resolved a specific tenant (slug must be valid and active).
// If the ?tenant= param was missing or unknown, TenantContext was never initialized.
try {
    $tenantId = TenantContext::getTenantId();
} catch (\RuntimeException $e) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized: invalid or missing tenant']);
    exit;
}

$formKey = trim($_GET['form'] ?? '');
if ($formKey === '') {
    http_response_code(400);
    echo json_encode(['error' => 'Missing form parameter']);
    exit;
}

// ?with=survey: deliver the published survey and theme as well (3.1). The frontend sends the ETag it
// got last time in If-None-Match and receives 304 while nothing changed. Without it the response is
// the config only, as in 3.0.
if (($_GET['with'] ?? '') === 'survey') {
    $delivery = new FormDeliveryService(new FormConfigRepository(), new FormResourceRepository());
    $bundle   = $delivery->bundle($formKey);
    if ($bundle === null) {
        http_response_code(404);
        echo json_encode(['error' => 'Form not found']);
        exit;
    }

    header('ETag: "' . $bundle['etag'] . '"');
    header('Cache-Control: no-cache');

    if (FormDeliveryService::etagMatches($_SERVER['HTTP_IF_NONE_MATCH'] ?? null, $bundle['etag'])) {
        http_response_code(304);
        exit;
    }

    // Defence in depth: never deliver stored content that fails today's validators.
    $bundle = $delivery->sanitized($bundle);
    foreach ($bundle['rejected'] as $r) {
        error_log(sprintf("form-config.php: %s of form '%s' not delivered (%d validation error(s): %s)", $r['kind'], $formKey, $r['errors'], $r['first']));
        \App\Services\AuditLogger::formEvent('form_delivery_rejected', $formKey, ['kind' => $r['kind'], 'errors' => $r['errors']]);
    }

    echo json_encode([
        'success'     => true,
        'config'      => $bundle['config'],
        // Survey and theme travel as JSON *text*: decoding and re-encoding would turn empty objects into arrays.
        'survey_json' => $bundle['survey_json'],
        'theme_json'  => $bundle['theme_json'],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$config = FormConfig::get($formKey);
if ($config === null) {
    http_response_code(404);
    echo json_encode(['error' => 'Form not found']);
    exit;
}

echo json_encode(['success' => true, 'config' => $config], JSON_UNESCAPED_UNICODE);
