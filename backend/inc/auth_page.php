<?php
declare(strict_types=1);

/**
 * Minimal page frame for the login-related pages (second factor, setup), which run before or
 * outside the normal navigation. Sends the Content-Security-Policy itself (these pages do not
 * include header.php); scripts only from assets/js/ (no inline handlers).
 */

use App\Services\AdminNetworkPolicy;
use App\Services\AuditLogger;
use App\Services\TwoFactorSession;
use App\Utils\ClientIp;
use App\Utils\ContentSecurityPolicy;

function auth_page_start(string $title): void
{
    ContentSecurityPolicy::send();
    ?><!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ondisos – <?= htmlspecialchars($title) ?></title>
    <link href="assets/bootstrap/bootstrap.min.css" rel="stylesheet">
    <style>
        body { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); min-height: 100vh; padding: 2rem 1rem; }
        .auth-card { max-width: 520px; margin: 0 auto; border: none; border-radius: 15px; box-shadow: 0 10px 40px rgba(0,0,0,.2); }
        .recovery-codes { font-family: monospace; font-size: 1.1rem; columns: 2; list-style: none; padding-left: 0; }
        .qr svg { max-width: 100%; height: auto; }
        @media print { body { background: #fff; } .no-print { display: none !important; } .auth-card { box-shadow: none; } }
    </style>
</head>
<body>
<div class="card auth-card">
    <div class="card-body p-4">
        <h1 class="h4 mb-3"><?= htmlspecialchars($title) ?></h1>
<?php
}

function auth_page_end(): void
{
    ?>
    </div>
</div>
<script src="assets/js/admin-ui.js"></script>
</body>
</html>
<?php
}

/**
 * Guard for pages that run on the intermediate state (password correct, second factor missing):
 * the network allowlist applies here too. Returns the pending state, or redirects and exits.
 *
 * @return array{admin_key: string, username: string, is_platform_admin: bool, tenant_id: ?int, mode: string, started: int, attempts: int}
 */
function auth_page_require_pending(string $mode): array
{
    $pending = TwoFactorSession::current();
    if ($pending === null || $pending['mode'] !== $mode) {
        header('Location: login.php');
        exit;
    }

    $policy = AdminNetworkPolicy::fromEnvironment();
    if (!$policy->isAllowed($pending['tenant_id'], $pending['is_platform_admin'], ClientIp::get())) {
        AuditLogger::adminNetworkDenied(basename($_SERVER['SCRIPT_NAME'] ?? ''), true);
        TwoFactorSession::clear();
        header('Location: login.php?network=1');
        exit;
    }

    return $pending;
}
