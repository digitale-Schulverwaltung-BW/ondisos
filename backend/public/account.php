<?php
// public/account.php
// "Mein Konto": status of the second factor, new recovery codes, removing the factor.

declare(strict_types=1);

require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/auth.php';
require_once __DIR__ . '/../inc/csrf.php';

use App\Config\Database;
use App\Repositories\TwoFactorRepository;
use App\Services\AuditLogger;
use App\Services\LoginService;
use App\Services\LoginThrottle;
use App\Services\RecoveryCodes;
use App\Services\TwoFactorCrypto;
use App\Services\TwoFactorPolicy;
use App\Utils\ClientIp;

if (empty($_SESSION['admin_key'])) {
    // Login switched off, or a session from before 3.1.2: there is no account behind it
    header('Location: index.php');
    exit;
}

$adminKey = (string)$_SESSION['admin_key'];
$username = (string)($_SESSION['admin_username'] ?? '');
$isPlatformAdmin = !empty($_SESSION['is_platform_admin']);
$policy = TwoFactorPolicy::fromEnvironment();
$repo = new TwoFactorRepository();
$required = $policy->isRequired($isPlatformAdmin) && !$policy->isDisabled();

$error = null;
$success = null;
$newCodes = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');
    $throttle = new LoginThrottle(__DIR__ . '/../cache/ratelimit-login');
    $clientIp = ClientIp::get($_SERVER);

    try {
        csrf_validate();
        $csrfOk = true;
    } catch (\InvalidArgumentException) {
        $csrfOk = false;
    }

    if (!$csrfOk) {
        $error = 'Ungültiger Sicherheitstoken. Bitte versuchen Sie es erneut.';
    } elseif ($throttle->isBlocked($clientIp, $username)) {
        $error = 'Zu viele fehlgeschlagene Versuche. Bitte versuchen Sie es später erneut.';
    } elseif (!$repo->hasFactor($adminKey)) {
        $error = 'Es ist keine Zwei-Faktor-Anmeldung eingerichtet.';
    } elseif ($action === 'remove' && $required) {
        $error = 'Die Zwei-Faktor-Anmeldung ist für Ihr Konto vorgeschrieben und kann nicht entfernt werden.';
    } elseif (in_array($action, ['remove', 'regenerate'], true)) {
        // Sensitive change: ask for the password again (a stolen session alone must not be enough)
        $passwordOk = (new LoginService())->verifyPasswordForKey($adminKey, (string)($_POST['password'] ?? ''), Database::getConnection());
        if (!$passwordOk) {
            $throttle->recordFailure($clientIp, $username);
            AuditLogger::loginFailed($username);
            usleep(500000);
            $error = 'Das Passwort ist nicht korrekt.';
        } elseif ($action === 'remove') {
            $repo->remove($adminKey, false);
            AuditLogger::twoFactorEvent('two_factor_removed', $username);
            $success = 'Die Zwei-Faktor-Anmeldung wurde entfernt.';
        } else {
            $newCodes = RecoveryCodes::generate();
            $repo->replaceRecoveryCodes($adminKey, array_map([RecoveryCodes::class, 'hash'], $newCodes));
            AuditLogger::twoFactorEvent('recovery_codes_regenerated', $username);
        }
    }
}

if (!empty($_SESSION['two_factor_notice'])) {
    $success = $success ?? (string)$_SESSION['two_factor_notice'];
    unset($_SESSION['two_factor_notice']);
}

$hasFactor = $repo->hasFactor($adminKey);
$remainingCodes = $hasFactor ? $repo->remainingRecoveryCodes($adminKey) : 0;
$keyMissing = !TwoFactorCrypto::isConfigured();

require __DIR__ . '/../inc/header.php';
?>
<div class="container">
    <h1 class="mb-4">Mein Konto</h1>

    <?php if ($success !== null): ?><div class="alert alert-success"><?= htmlspecialchars($success) ?></div><?php endif; ?>
    <?php if ($error !== null): ?><div class="alert alert-danger"><?= htmlspecialchars($error) ?></div><?php endif; ?>

    <div class="card mb-4">
        <div class="card-header"><h5 class="mb-0">Konto</h5></div>
        <div class="card-body">
            Angemeldet als <strong><?= htmlspecialchars($username) ?></strong>
            (<?= $isPlatformAdmin ? 'Platform-Admin' : 'Schul-Admin' ?>).
        </div>
    </div>

    <div class="card mb-4">
        <div class="card-header"><h5 class="mb-0">Zwei-Faktor-Anmeldung</h5></div>
        <div class="card-body">
            <?php if ($newCodes !== null): ?>
                <div class="alert alert-warning">
                    <strong>Neue Recovery-Codes – jetzt sichern! Sie werden nur einmal angezeigt.</strong> Die alten Codes sind ungültig.
                </div>
                <ul class="list-unstyled font-monospace border rounded p-3 bg-light">
                    <?php foreach ($newCodes as $code): ?><li><?= htmlspecialchars($code) ?></li><?php endforeach; ?>
                </ul>
            <?php endif; ?>

            <?php if ($hasFactor): ?>
                <p><span class="badge bg-success">aktiv</span> Authenticator-App eingerichtet.
                    Übrige Recovery-Codes: <strong><?= $remainingCodes ?></strong>.</p>

                <form method="POST" action="account.php" class="row g-2 align-items-end mb-3">
                    <?php csrf_field(); ?>
                    <input type="hidden" name="action" value="regenerate">
                    <div class="col-md-5">
                        <label for="pw_regen" class="form-label">Passwort zur Bestätigung</label>
                        <input type="password" id="pw_regen" name="password" class="form-control" required autocomplete="current-password">
                    </div>
                    <div class="col-md-4"><button type="submit" class="btn btn-outline-primary">Neue Recovery-Codes erzeugen</button></div>
                </form>

                <?php if ($required): ?>
                    <p class="text-muted mb-0">Die Zwei-Faktor-Anmeldung ist für Ihr Konto vorgeschrieben und kann nicht entfernt werden.</p>
                <?php else: ?>
                    <form method="POST" action="account.php" class="row g-2 align-items-end"
                          data-confirm="Zwei-Faktor-Anmeldung wirklich entfernen?">
                        <?php csrf_field(); ?>
                        <input type="hidden" name="action" value="remove">
                        <div class="col-md-5">
                            <label for="pw_remove" class="form-label">Passwort zur Bestätigung</label>
                            <input type="password" id="pw_remove" name="password" class="form-control" required autocomplete="current-password">
                        </div>
                        <div class="col-md-4"><button type="submit" class="btn btn-outline-danger">Entfernen</button></div>
                    </form>
                <?php endif; ?>
            <?php else: ?>
                <p><span class="badge bg-secondary">nicht eingerichtet</span>
                    <?= $required ? 'Für Ihr Konto ist sie vorgeschrieben.' : 'Sie ist für Ihr Konto freiwillig, schützt es aber deutlich besser.' ?></p>
                <?php if ($keyMissing): ?>
                    <div class="alert alert-warning mb-0"><code>TWO_FACTOR_KEY</code> fehlt in der <code>.env</code>; ohne ihn ist die Einrichtung nicht möglich.</div>
                <?php else: ?>
                    <a href="two_factor_setup.php" class="btn btn-primary">Jetzt einrichten</a>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>
</div>
<?php require __DIR__ . '/../inc/footer.php'; ?>
