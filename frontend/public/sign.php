<?php
// frontend/public/sign.php
//
// Phone page of the signature on a smartphone (3.2.0a). Opened from the QR code on the computer:
// sign.php#s=<session id>&t=<write token>. The secrets stay in the URL fragment (never sent to the
// server, not in logs or the Referer); js/sign-page.js reads them and talks to api/signature.php.
// The page itself carries no personal data.

declare(strict_types=1);

require_once __DIR__ . '/../inc/bootstrap.php';

use Frontend\Services\MessageService;
use Frontend\Utils\ContentSecurityPolicy;

$messages = MessageService::getAll()['signature'] ?? [];
$t = static fn(string $key): string => htmlspecialchars((string)($messages[$key] ?? $key), ENT_QUOTES);
// Cache busting: a phone that kept an old copy gets the new file after an update
$v = static fn(string $file): string => $file . '?v=' . (string)@filemtime(__DIR__ . '/' . $file);

header('Cache-Control: no-store');
header('Referrer-Policy: no-referrer');
header('X-Robots-Tag: noindex, nofollow');
ContentSecurityPolicy::send();
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0">
    <!-- No zoom: Safari kept zooming in on rotation or a double tap while signing; the page holds only the pad -->
    <meta name="robots" content="noindex, nofollow">
    <title><?= $t('page_title') ?></title>
    <link href="assets/survey-core.fontless.min.css" rel="stylesheet">
    <script src="assets/survey.core.min.js"></script>
    <script src="assets/survey-js-ui.min.js"></script>
    <link href="<?= $v('assets/sign-page.css') ?>" rel="stylesheet">
</head>
<body>
    <main class="sign">
        <header class="sign__head">
            <div class="sign__school" id="signSchool"></div>
            <h1 class="sign__form" id="signForm"><?= $t('page_title') ?></h1>
            <div class="sign__field" id="signField"></div>
        </header>

        <div class="sign__bar" id="signBar" hidden>
            <span class="sign__countdown" id="signCountdown"></span>
            <button type="button" class="sign__landscape" id="signLandscape" hidden><?= $t('landscape_button') ?></button>
        </div>
        <p class="sign__rotate" id="signRotate" hidden><?= $t('rotate_hint') ?></p>

        <div class="sign__padwrap"><div id="signPad"></div></div>
        <button type="button" class="sign__send" id="signSend" hidden><?= $t('send') ?></button>

        <p class="sign__status" id="signStatus" role="status" aria-live="polite"><?= $t('loading') ?></p>
    </main>

    <script src="<?= $v('js/sign-page.js') ?>"></script>
    <script nonce="<?= ContentSecurityPolicy::nonce() ?>">
        SignPage.start({
            endpoint: 'api/signature.php',
            messages: <?= json_encode($messages, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?>
        });
    </script>
</body>
</html>
