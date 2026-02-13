<?php
declare(strict_types=1);

// Bootstrap without auth check
define('SKIP_AUTH_CHECK', true);
require_once __DIR__ . '/../inc/bootstrap.php';

use App\Services\AuditLogger;

session_start();

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
        // Get credentials from env
        $adminUsername = $_ENV['ADMIN_USERNAME'] ?? '';
        $adminPasswordHash = $_ENV['ADMIN_PASSWORD_HASH'] ?? '';

        if (empty($adminUsername) || empty($adminPasswordHash)) {
            $error = 'Admin-Zugangsdaten nicht konfiguriert. Bitte .env prüfen.';
        } elseif ($username === $adminUsername && password_verify($password, $adminPasswordHash)) {
            // Login successful
            $_SESSION['admin_logged_in'] = true;
            $_SESSION['admin_username'] = $username;
            $_SESSION['login_time'] = time();

            // Regenerate session ID to prevent session fixation
            session_regenerate_id(true);

            AuditLogger::loginSuccess($username);

            // Redirect to index
            header('Location: index.php');
            exit;
        } else {
            $error = 'Benutzername oder Passwort falsch.';
            AuditLogger::loginFailed($username);
            // Add small delay to prevent brute force
            usleep(500000); // 0.5 seconds
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
    <title>Admin Login - Anmeldungssystem</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
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
                <h4 class="mb-0">Anmeldungssystem</h4>
                <small>Admin-Bereich</small>
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
                <small>Anmeldungssystem v2.2</small>
            </div>
        </div>
    </div>
</body>
</html>
