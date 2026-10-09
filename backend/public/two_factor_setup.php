<?php
declare(strict_types=1);

// Setup wizard for the authenticator app: QR code -> confirm with a code -> recovery codes -> done.
// Runs in two contexts:
//   - pending: password correct, but a second factor is mandatory and missing (mode "setup"). Not a
//     login yet; the login is completed when the wizard ends. The network allowlist applies as well.
//   - session: a logged-in admin sets up voluntarily (grace logins, optional factor); inc/auth.php applies.
// Nothing is stored before the last step: leaving the wizard leaves no half-enrolled factor.
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
use App\Utils\QrCodeSvg;

$pendingContext = empty($_SESSION['admin_logged_in']) && TwoFactorSession::current() !== null;

if ($pendingContext) {
    $pending = TwoFactorSession::current();
    if ($pending !== null && $pending['mode'] === TwoFactorSession::MODE_CODE) {
        header('Location: two_factor.php');
        exit;
    }
    $pending = auth_page_require_pending(TwoFactorSession::MODE_SETUP);
    $identity = [
        'admin_key' => $pending['admin_key'],
        'username' => $pending['username'],
        'is_platform_admin' => $pending['is_platform_admin'],
        'tenant_id' => $pending['tenant_id'],
    ];
} else {
    require_once __DIR__ . '/../inc/auth.php';
    if (empty($_SESSION['admin_key'])) {
        // Login switched off or session from before 3.1.2: no account to attach a factor to
        header('Location: index.php');
        exit;
    }
    $identity = [
        'admin_key' => (string)$_SESSION['admin_key'],
        'username' => (string)($_SESSION['admin_username'] ?? ''),
        'is_platform_admin' => !empty($_SESSION['is_platform_admin']),
        'tenant_id' => isset($_SESSION['tenant_id']) ? (int)$_SESSION['tenant_id'] : null,
    ];
}

$adminKey = $identity['admin_key'];
$username = $identity['username'];
$totp = new TotpService();
$error = null;
$keyMissing = !TwoFactorCrypto::isConfigured();
$repo = new TwoFactorRepository();

if (!$keyMissing && $repo->hasFactor($adminKey)) {
    // Already enrolled (e.g. second tab): management is in "Mein Konto"
    header('Location: ' . ($pendingContext ? 'two_factor.php' : 'account.php'));
    exit;
}

$state = $_SESSION['tf_setup'] ?? [];
if (!is_array($state)) {
    $state = [];
}

if (!$keyMissing && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');
    try {
        csrf_validate();
        $csrfOk = true;
    } catch (\InvalidArgumentException) {
        $csrfOk = false;
    }

    if (!$csrfOk) {
        $error = 'Ungültiger Sicherheitstoken. Bitte versuchen Sie es erneut.';
    } elseif ($action === 'restart') {
        unset($_SESSION['tf_setup']);
        header('Location: two_factor_setup.php');
        exit;
    } elseif ($action === 'verify' && isset($state['secret']) && !isset($state['codes'])) {
        $throttle = new LoginThrottle(__DIR__ . '/../cache/ratelimit-2fa');
        $clientIp = ClientIp::get($_SERVER);
        if ($throttle->isBlocked($clientIp, $adminKey)) {
            $error = 'Zu viele falsche Codes. Bitte versuchen Sie es später erneut.';
        } else {
            $step = $totp->verify((string)$state['secret'], (string)($_POST['code'] ?? ''), null);
            if ($step === null) {
                $throttle->recordFailure($clientIp, $adminKey);
                usleep(500000);
                $error = 'Der Code stimmt nicht. Prüfen Sie die Uhrzeit Ihres Geräts und versuchen Sie es mit dem aktuellen Code erneut.';
                if ($pendingContext && !TwoFactorSession::recordFailedAttempt()) {
                    header('Location: login.php?twofactor=failed');
                    exit;
                }
            } else {
                $throttle->recordSuccess($clientIp, $adminKey);
                $_SESSION['tf_setup']['verified_step'] = $step;
                $_SESSION['tf_setup']['codes'] = RecoveryCodes::generate();
                header('Location: two_factor_setup.php');
                exit;
            }
        }
    } elseif ($action === 'done' && isset($state['secret'], $state['verified_step'], $state['codes']) && !empty($_POST['saved'])) {
        $codes = $state['codes'];
        $repo->enable(
            $adminKey,
            TwoFactorCrypto::fromEnvironment()->encrypt((string)$state['secret']),
            (int)$state['verified_step'],
            array_map([RecoveryCodes::class, 'hash'], $codes)
        );
        unset($_SESSION['tf_setup']);
        AuditLogger::twoFactorEvent('two_factor_enabled', $username);

        if ($pendingContext) {
            LoginSession::establish($username, $identity['is_platform_admin'], $identity['tenant_id'], $adminKey);
            AuditLogger::loginSuccess($username);
            $_SESSION['two_factor_notice'] = 'Die Zwei-Faktor-Anmeldung ist eingerichtet.';
            header('Location: index.php');
        } else {
            $_SESSION['two_factor_notice'] = 'Die Zwei-Faktor-Anmeldung ist eingerichtet.';
            header('Location: account.php');
        }
        exit;
    } elseif ($action === 'done') {
        $error = 'Bitte bestätigen Sie, dass Sie die Recovery-Codes gespeichert haben.';
    }
    $state = $_SESSION['tf_setup'] ?? [];
}

if (!$keyMissing && !isset($state['secret'])) {
    $_SESSION['tf_setup'] = $state = ['secret' => $totp->generateSecret()];
}

auth_page_start('Zwei-Faktor-Anmeldung einrichten');
?>
        <?php if ($keyMissing): ?>
            <div class="alert alert-danger">
                <code>TWO_FACTOR_KEY</code> fehlt in der <code>.env</code>. Erzeugen Sie einen Schlüssel mit
                <code>openssl rand -hex 32</code>, tragen Sie ihn dort ein und starten Sie das Backend neu.
            </div>
            <a href="<?= $pendingContext ? 'logout.php' : 'index.php' ?>" class="btn btn-secondary">Zurück</a>
        <?php elseif (isset($state['codes'])): ?>
            <p><strong>Schritt 2 von 2: Recovery-Codes sichern.</strong></p>
            <p>Mit diesen Codes kommen Sie hinein, wenn Ihr Gerät nicht verfügbar ist. Jeder Code gilt <strong>einmal</strong>.
                Bewahren Sie sie getrennt vom Gerät auf (Passwortmanager oder Ausdruck). Sie werden nur jetzt angezeigt.</p>
            <?php if ($error !== null): ?><div class="alert alert-danger"><?= htmlspecialchars($error) ?></div><?php endif; ?>
            <ul class="recovery-codes border rounded p-3 bg-light">
                <?php foreach ($state['codes'] as $code): ?><li><?= htmlspecialchars($code) ?></li><?php endforeach; ?>
            </ul>
            <div class="d-flex gap-2 mb-3 no-print">
                <a class="btn btn-outline-secondary btn-sm" download="ondisos-recovery-codes.txt"
                   href="data:text/plain;charset=utf-8,<?= rawurlencode("ondisos Recovery-Codes für " . $username . "\n\n" . implode("\n", $state['codes']) . "\n") ?>">Als Textdatei speichern</a>
                <button type="button" class="btn btn-outline-secondary btn-sm" data-print>Drucken</button>
            </div>
            <form method="POST" action="two_factor_setup.php" class="no-print">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
                <input type="hidden" name="action" value="done">
                <div class="form-check mb-3">
                    <input class="form-check-input" type="checkbox" id="saved" name="saved" value="1" required>
                    <label class="form-check-label" for="saved">Ich habe die Recovery-Codes gespeichert.</label>
                </div>
                <button type="submit" class="btn btn-primary w-100">Fertig</button>
            </form>
        <?php else: ?>
            <p><strong>Schritt 1 von 2: Authenticator-App verbinden.</strong></p>
            <ol>
                <li>Öffnen Sie eine Authenticator-App (z. B. Aegis, FreeOTP, Google oder Microsoft Authenticator).</li>
                <li>Scannen Sie den QR-Code oder geben Sie den Schlüssel von Hand ein.</li>
                <li>Tragen Sie den angezeigten 6-stelligen Code ein.</li>
            </ol>
            <?php if ($error !== null): ?><div class="alert alert-danger"><?= htmlspecialchars($error) ?></div><?php endif; ?>
            <div class="qr text-center mb-2"><?= QrCodeSvg::render($totp->provisioningUri((string)$state['secret'], $username)) ?></div>
            <p class="small text-muted text-center">Schlüssel zur manuellen Eingabe:<br>
                <code class="user-select-all"><?= htmlspecialchars(trim(chunk_split((string)$state['secret'], 4, ' '))) ?></code></p>
            <form method="POST" action="two_factor_setup.php" autocomplete="off">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
                <input type="hidden" name="action" value="verify">
                <div class="mb-3">
                    <label for="code" class="form-label">Code aus der App</label>
                    <input type="text" id="code" name="code" class="form-control form-control-lg" required autofocus
                           inputmode="numeric" autocomplete="one-time-code" maxlength="10" spellcheck="false">
                </div>
                <button type="submit" class="btn btn-primary w-100">Weiter</button>
            </form>
            <p class="mt-3 mb-0 small">
                <?php if ($pendingContext): ?>
                    <a href="logout.php">Abbrechen und abmelden</a>
                <?php else: ?>
                    <a href="index.php">Später</a>
                <?php endif; ?>
            </p>
        <?php endif; ?>
<?php
auth_page_end();
