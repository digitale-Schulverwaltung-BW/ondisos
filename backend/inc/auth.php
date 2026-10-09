<?php
declare(strict_types=1);

// Start session if not already started.
// bootstrap.php also starts the session (with the same guard), but auth.php
// may be included in contexts where bootstrap runs first — the guard is safe.
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Skip auth check if explicitly disabled (e.g., for login.php)
if (defined('SKIP_AUTH_CHECK') && SKIP_AUTH_CHECK === true) {
    return;
}

// MULTI_TENANT_ENABLED=true forces auth on, regardless of AUTH_ENABLED setting.
// When multi-tenant is active, every browser request must have a valid session.
$multiTenantEnabled = filter_var(\App\Config\EnvLoader::get('MULTI_TENANT_ENABLED', 'false'), FILTER_VALIDATE_BOOLEAN);
$authEnabled = filter_var(\App\Config\EnvLoader::get('AUTH_ENABLED', 'false'), FILTER_VALIDATE_BOOLEAN);

$networkPolicy = \App\Services\AdminNetworkPolicy::fromEnvironment();
$clientIp = \App\Utils\ClientIp::get();

if (!$authEnabled && !$multiTenantEnabled) {
    // Auth fully disabled (single tenant, administration network only): everyone counts as platform
    // admin, so the network is the only protection. PLATFORM_ADMIN_ALLOWED_NETWORKS enforces it
    // (empty = no restriction). Checked before returning, /api/* and pdf/download.php never get here.
    if (!$networkPolicy->isAllowedWithoutLogin($clientIp)) {
        \App\Services\AuditLogger::adminNetworkDenied(basename($_SERVER['SCRIPT_NAME'] ?? ''), false);
        http_response_code(403);
        header('Content-Type: text/plain; charset=UTF-8');
        echo "403 Forbidden\n";
        exit;
    }

    return;
}

// Auth enabled (explicitly or forced by MULTI_TENANT_ENABLED) — check login state
if (empty($_SESSION['admin_logged_in'])) {
    // Not logged in — redirect to login page
    header('Location: login.php');
    exit;
}

// Network allowlist on every request, not only at login: a stolen session must not work from
// a foreign network. Platform admins use the global list, tenant admins the list of their tenant.
$sessionIsPlatformAdmin = !empty($_SESSION['is_platform_admin']);
$sessionTenantId = isset($_SESSION['tenant_id']) ? (int)$_SESSION['tenant_id'] : null;
if (!$networkPolicy->isAllowed($sessionTenantId, $sessionIsPlatformAdmin, $clientIp)) {
    \App\Services\AuditLogger::adminNetworkDenied(basename($_SERVER['SCRIPT_NAME'] ?? ''), true);
    $_SESSION = [];
    session_regenerate_id(true);
    session_destroy();
    header('Location: login.php?network=1');
    exit;
}

// Optional: Check session timeout (if SESSION_LIFETIME is set)
$sessionLifetime = (int)($_ENV['SESSION_LIFETIME'] ?? 3600);
$loginTime = $_SESSION['login_time'] ?? 0;

if ($loginTime > 0 && (time() - $loginTime) > $sessionLifetime) {
    // Session expired — destroy and redirect
    session_regenerate_id(true);
    session_destroy();
    header('Location: login.php?expired=1');
    exit;
}

// Multi-tenant: verify tenant context is correctly set for this session.
// A logged-in session that has neither is_platform_admin nor tenant_id is
// a data-integrity error (e.g. stale session from before Phase 2 migration).
// Force re-login to prevent operating without a valid TenantContext.
if ($multiTenantEnabled) {
    $isPlatformAdmin = !empty($_SESSION['is_platform_admin']);
    $hasTenantId = !empty($_SESSION['tenant_id']);

    if (!$isPlatformAdmin && !$hasTenantId) {
        // Logged in but no tenant context — force re-login
        session_regenerate_id(true);
        session_destroy();
        header('Location: login.php');
        exit;
    }
}

// Platform admin without a chosen school has no tenant context (there is no cross-tenant view).
// Only pages that cope with that (tenant management, form editor shell, own account) are reachable;
// everything else, i.e. all pages with Anmeldungen, sends the admin to the tenant list.
if ($multiTenantEnabled && !empty($_SESSION['is_platform_admin']) && !\App\Config\TenantContext::isInitialized()) {
    $scriptName = basename($_SERVER['SCRIPT_NAME'] ?? '');
    $withoutTenant = ['tenants.php', 'tenant_logo.php', 'account.php', 'two_factor_setup.php', 'logout.php',
        'forms.php', 'form_edit.php', 'form_survey.php', 'form_preview.php', 'form_preview_frame.php', 'form_pdf_preview.php'];
    if (!in_array($scriptName, $withoutTenant, true)) {
        header('Location: tenants.php?choose=1');
        exit;
    }
}
