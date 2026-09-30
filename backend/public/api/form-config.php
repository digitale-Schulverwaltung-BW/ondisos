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

$config = FormConfig::get($formKey);
if ($config === null) {
    http_response_code(404);
    echo json_encode(['error' => 'Form not found']);
    exit;
}

echo json_encode(['success' => true, 'config' => $config], JSON_UNESCAPED_UNICODE);
