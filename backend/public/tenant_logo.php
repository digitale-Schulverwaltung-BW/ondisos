<?php
// public/tenant_logo.php
// Shows the PDF logo of the current tenant in the editor (thumbnail). Session-protected; the file itself is not served from uploads/.

declare(strict_types=1);

require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/auth.php';
require_once __DIR__ . '/../inc/form_editor.php';

use App\Config\TenantContext;
use App\Services\TenantLogoService;

if ($editorNoTenant) {
    http_response_code(404);
    exit;
}

$logos = new TenantLogoService();
$path  = $logos->path(TenantContext::getTenantId());
$info  = $logos->info(TenantContext::getTenantId());
if ($path === null || $info === null) {
    http_response_code(404);
    exit;
}

header('Content-Type: ' . $info['mime']);
header('Content-Length: ' . $info['bytes']);
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-cache');
readfile($path);
