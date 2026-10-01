<?php
declare(strict_types=1);

// Bootstrap without auth check
define('SKIP_AUTH_CHECK', true);
require_once __DIR__ . '/../inc/bootstrap.php';

use App\Services\AuditLogger;
use App\Services\LoginService;
use App\Config\Database;

// Session was started in bootstrap.php (with PHP_SESSION_NONE guard).
// Guard here handles the edge case where bootstrap did not start it (e.g. when
// session_start() was already called before bootstrap, or CLI context).
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Redirect if already logged in
if (!empty($_SESSION['admin_logged_in'])) {
    header('Location: index.php');
    exit;
}

$error = null;
$info = null;

// Check for expired session
if (isset($_GET['expired'])) {
    $info = 'Ihre Sitzung ist abgelaufen. Bitte melden Sie sich erneut an.';
}

// Handle login POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $csrfToken = $_POST['csrf_token'] ?? '';

    // CSRF check
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $csrfToken)) {
        $error = 'Ungültiger Sicherheitstoken. Bitte versuchen Sie es erneut.';
    } else {
        $loginService = new LoginService();
        $loggedIn = false;
        $isPlatformAdmin = false;
        $tenantId = null;

        // --- Path 1: Platform admin via .env credentials ---
        $multiTenantEnabled = filter_var(
            $_ENV['MULTI_TENANT_ENABLED'] ?? 'false',
            FILTER_VALIDATE_BOOLEAN
        );

        $platformLoginEnabled = !$multiTenantEnabled
            || !empty($_ENV['ADMIN_USERNAME']);

        if ($platformLoginEnabled && $loginService->attemptPlatformAdminLogin($username, $password)) {
            $loggedIn = true;
            $isPlatformAdmin = true;
        }

        // --- Path 2: Tenant admin via DB ---
        if (!$loggedIn) {
            try {
                $db = Database::getConnection();
                $tenantRow = $loginService->attemptTenantAdminLogin($username, $password, $db);
                if ($tenantRow !== null) {
                    $loggedIn = true;
                    $isPlatformAdmin = false;
                    $tenantId = (int)$tenantRow['tenant_id'];
                }
            } catch (\Throwable $e) {
                // DB not available or misconfigured — fall through to error
                error_log('LoginService::attemptTenantAdminLogin failed: ' . $e->getMessage());
            }
        }

        if ($loggedIn) {
            // Regenerate session ID to prevent session fixation
            session_regenerate_id(true);

            // Core session keys (existing)
            $_SESSION['admin_logged_in'] = true;
            $_SESSION['admin_username'] = $username;
            $_SESSION['login_time'] = time();

            // Phase 2: multi-tenant session keys
            $_SESSION['is_platform_admin'] = $isPlatformAdmin;
            if (!$isPlatformAdmin && $tenantId !== null) {
                $_SESSION['tenant_id'] = $tenantId;
            }

            AuditLogger::loginSuccess($username);

            // Redirect to index
            header('Location: index.php');
            exit;
        } else {
            $error = 'Benutzername oder Passwort falsch.';
            AuditLogger::loginFailed($username);
            // Brute-force protection: 0.5s delay on failed login
            usleep(500000);
        }
    }
}

// Generate CSRF token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['csrf_token'];
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ondisos – Anmeldung zum Admin-Bereich</title>
    <link href="assets/bootstrap/bootstrap.min.css" rel="stylesheet">
    <style>
        body {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .login-card {
            max-width: 400px;
            width: 100%;
        }
        .card {
            border: none;
            border-radius: 15px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.2);
        }
        .card-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-radius: 15px 15px 0 0 !important;
            padding: 1.5rem;
        }
        .btn-login {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border: none;
            padding: 0.75rem;
            font-weight: 500;
        }
        .btn-login:hover {
            opacity: 0.9;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        }
    </style>
</head>
<body>
    <div class="login-card">
        <div class="card">
            <div class="card-header text-center">
                <h1 class="mb-0 fw-bold" style="font-size: 2.2rem; letter-spacing: .03em;">ondisos</h1>
                <small>Anmeldungssystem · Admin-Bereich</small>
            </div>
            <div class="card-body p-4">
                <?php if ($error): ?>
                    <div class="alert alert-danger" role="alert">
                        <?= htmlspecialchars($error) ?>
                    </div>
                <?php endif; ?>

                <?php if ($info): ?>
                    <div class="alert alert-info" role="alert">
                        <?= htmlspecialchars($info) ?>
                    </div>
                <?php endif; ?>

                <form method="POST" action="">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">

                    <div class="mb-3">
                        <label for="username" class="form-label">Benutzername</label>
                        <input
                            type="text"
                            class="form-control"
                            id="username"
                            name="username"
                            required
                            autofocus
                            autocomplete="username"
                        >
                    </div>

                    <div class="mb-3">
                        <label for="password" class="form-label">Passwort</label>
                        <input
                            type="password"
                            class="form-control"
                            id="password"
                            name="password"
                            required
                            autocomplete="current-password"
                        >
                    </div>

                    <div class="d-grid">
                        <button type="submit" class="btn btn-primary btn-login">
                            Anmelden
                        </button>
                    </div>
                </form>
            </div>
            <div class="card-footer text-center text-muted">
                <small>ondisos – Anmeldungssystem</small>
            </div>
        </div>
    </div>
</body>
</html>
