<?php
declare(strict_types=1);

// Second factor after the password. Runs on the intermediate state (pending_2fa), which is NOT a
// login: inc/auth.php does not read it, so every protected page stays closed until this page succeeds.
define('SKIP_AUTH_CHECK', true);
require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/csrf.php';
require_once __DIR__ . '/../inc/auth_page.php';

use App\Repositories\TwoFactorRepository;
use App\Services\AuditLogger;
use App\Services\LoginSession;
use App\Services\LoginThrottle;
use App\Services\RecoveryCodes;
use App\Services\TotpService;
use App\Services\TwoFactorCrypto;
use App\Services\TwoFactorSession;
use App\Utils\ClientIp;

if (!empty($_SESSION['admin_logged_in'])) {
    header('Location: index.php');
    exit;
}

$pending = auth_page_require_pending(TwoFactorSession::MODE_CODE);
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $throttle = new LoginThrottle(__DIR__ . '/../cache/ratelimit-2fa');
    $clientIp = ClientIp::get($_SERVER);
    $adminKey = $pending['admin_key'];
    $username = $pending['username'];

    try {
        csrf_validate();
        $csrfOk = true;
    } catch (\InvalidArgumentException) {
        $csrfOk = false;
    }

    if (!$csrfOk) {
        $error = 'Ungültiger Sicherheitstoken. Bitte versuchen Sie es erneut.';
    } elseif ($throttle->isBlocked($clientIp, $adminKey)) {
        $error = 'Zu viele falsche Codes. Bitte versuchen Sie es später erneut.';
        AuditLogger::loginBlocked($username, $throttle->retryAfter($clientIp, $adminKey));
        usleep(500000);
    } else {
        $input = trim((string)($_POST['code'] ?? ''));
        $accepted = false;
        $usedRecovery = false;
        $unavailable = false;

        try {
            $repo = new TwoFactorRepository();
            $row = $repo->find($adminKey);
            if ($row !== null && $row['totp_secret_enc'] !== null) {
                if (RecoveryCodes::looksLikeCode($input)) {
                    $accepted = $repo->consumeRecoveryCode($adminKey, RecoveryCodes::hash($input));
                    $usedRecovery = $accepted;
                } else {
                    $secret = TwoFactorCrypto::fromEnvironment()->decrypt($row['totp_secret_enc']);
                    $step = (new TotpService())->verify($secret, $input, $row['last_totp_step']);
                    // acceptTotpStep() is atomic: of two parallel requests with the same code only one wins
                    $accepted = $step !== null && $repo->acceptTotpStep($adminKey, $step);
                }
            }
        } catch (\Throwable $e) {
            error_log('Two-factor verification failed: ' . $e->getMessage());
            $unavailable = true;
        }

        if ($unavailable) {
            $error = 'Die Zwei-Faktor-Prüfung ist derzeit nicht verfügbar. Bitte wenden Sie sich an den Betreiber (TWO_FACTOR_KEY, Datenbank).';
        } elseif ($accepted) {
            $throttle->recordSuccess($clientIp, $adminKey);
            $remaining = $usedRecovery ? $repo->remainingRecoveryCodes($adminKey) : null;
            LoginSession::establish($username, $pending['is_platform_admin'], $pending['tenant_id'], $adminKey);
            AuditLogger::loginSuccess($username);
            AuditLogger::twoFactorEvent($usedRecovery ? 'recovery_code_used' : 'two_factor_success', $username, $usedRecovery ? ['remaining' => $remaining] : []);
            if ($usedRecovery) {
                $_SESSION['two_factor_notice'] = sprintf(
                    'Sie haben einen Recovery-Code verwendet (noch %d übrig). Richten Sie unter „Mein Konto“ bei Bedarf einen neuen Authenticator ein und erzeugen Sie neue Codes.',
                    $remaining
                );
            }
            header('Location: index.php');
            exit;
        } else {
            $throttle->recordFailure($clientIp, $adminKey);
            AuditLogger::twoFactorEvent('two_factor_failed', $username);
            usleep(500000);
            if (!TwoFactorSession::recordFailedAttempt()) {
                header('Location: login.php?twofactor=failed');
                exit;
            }
            $error = 'Der Code ist ungültig oder abgelaufen.';
        }
    }
}

auth_page_start('Zwei-Faktor-Anmeldung');
?>
        <p class="text-muted">Angemeldet als <strong><?= htmlspecialchars($pending['username']) ?></strong>.
            Geben Sie den 6-stelligen Code Ihrer Authenticator-App ein, oder einen Recovery-Code.</p>

        <?php if ($error !== null): ?>
            <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <form method="POST" action="two_factor.php" autocomplete="off">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
            <div class="mb-3">
                <label for="code" class="form-label">Code</label>
                <input type="text" id="code" name="code" class="form-control form-control-lg" required autofocus
                       autocomplete="one-time-code" maxlength="32" spellcheck="false">
            </div>
            <button type="submit" class="btn btn-primary w-100">Bestätigen</button>
        </form>
        <p class="mt-3 mb-0 small"><a href="logout.php">Abbrechen und neu anmelden</a></p>
<?php
auth_page_end();
