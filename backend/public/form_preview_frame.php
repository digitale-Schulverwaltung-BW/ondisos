<?php
// public/form_preview_frame.php
// The sandboxed document of the survey preview: renders a survey (draft or published) in the SurveyJS runtime.
// Shown inside form_preview.php; never opened directly by a link in the admin.
//
// "Content-Security-Policy: sandbox allow-scripts" gives this document an opaque origin: even if a stored survey
// contained script, it could not read the admin session, call admin pages with the admin's cookies or touch the
// surrounding page. On top of that only surveys that pass SurveyValidator are rendered at all.

declare(strict_types=1);

require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/auth.php';
require_once __DIR__ . '/../inc/form_editor.php';

use App\Controllers\SurveyPreviewController;
use App\Repositories\FormConfigRepository;
use App\Repositories\FormDraftRepository;
use App\Repositories\FormResourceRepository;
use App\Services\MessageService as M;

// A sandboxed document has an opaque origin, so 'self' would match nothing: scripts are allowed by nonce instead.
$nonce = base64_encode(random_bytes(16));
header("Content-Security-Policy: sandbox allow-scripts; default-src 'none'; script-src 'nonce-{$nonce}' 'unsafe-eval'; style-src * 'unsafe-inline' data:; font-src data:; img-src * data: blob:; connect-src *; frame-ancestors 'self'");
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex');
header('Content-Type: text/html; charset=utf-8');

$e = static fn (mixed $v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');

if ($editorNoTenant) {
    http_response_code(400);
    die('Kein Tenant gewählt.');
}

$formKey = is_string($_GET['form'] ?? null) ? $_GET['form'] : '';
$source  = is_string($_GET['source'] ?? null) ? $_GET['source'] : SurveyPreviewController::SOURCE_DRAFT;

$preview = (new SurveyPreviewController($configs, $resources, $drafts))->load($formKey, $source);

$message = null;
switch ($preview['status']) {
    case 'not_found':
        http_response_code(404);
        $message = M::get('forms.not_found', 'Dieses Formular gibt es nicht.');
        break;
    case 'nothing':
        $message = M::get('preview.nothing', 'Zu diesem Formular gibt es im Backend noch keine Survey, die sich anzeigen ließe (sie liegt als Datei im Frontend).');
        break;
    case 'invalid':
        $message = M::get('preview.invalid', 'Diese Survey enthält Inhalte, die nicht angezeigt werden dürfen. Bitte im Survey-Editor korrigieren:')
            . ' ' . implode(' · ', array_map(static fn (array $x): string => ($x['path'] !== '' ? $x['path'] . ': ' : '') . $x['message'], array_slice($preview['errors'], 0, 5)));
        break;
}

/** Fonts as data URIs: a sandboxed document has no origin and could not load them from the server. */
$font = static function (string $file): string {
    $path = __DIR__ . '/assets/preview/fonts/' . $file;
    return is_file($path) ? 'data:font/woff2;base64,' . base64_encode((string)file_get_contents($path)) : '';
};
?><!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Vorschau</title>
<?php if ($message === null): ?>
    <link href="assets/preview/survey-core.fontless.min.css" rel="stylesheet">
<?php endif; ?>
    <style>
        @font-face { font-family: 'Open Sans'; font-weight: 400; font-style: normal; src: url('<?= $font('open-sans-v44-latin-regular.woff2') ?>') format('woff2'); }
        @font-face { font-family: 'Open Sans'; font-weight: 700; font-style: normal; src: url('<?= $font('open-sans-v44-latin-700.woff2') ?>') format('woff2'); }
        :root {
            --font-family: 'Open Sans', sans-serif !important;
        }

        body {
            font-family: var(--font-family);
            margin: 0;
            padding: 20px;
            background-color: #f5f5f5;
        }

        #surveyContainer {
            margin: 0 auto;
            max-width: 900px;
            background: white;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }

        /*
         * Reset theme interference on SurveyJS dropdown filter input.
         * Some themes set padding:1%, width:98%, margin:0 0 30px on input[type="text"]
         * which inflates .sd-dropdown__filter-string-input (SurveyJS expects padding:0).
         * Selector specificity (0,2,0) beats typical theme rules (0,1,1).
         */
        #surveyContainer .sd-dropdown__filter-string-input {
            padding: 0 !important;
            width: 100% !important;
            margin: 0 !important;
            border: none !important;
            border-radius: 0 !important;
            background: transparent !important;
            line-height: calc(1.5 * var(--sjs-font-editorfont-size, var(--sjs-font-size, 16px))) !important;
            box-sizing: content-box !important;
        }
        #preview-note { max-width: 900px; margin: 0 auto 12px; padding: 8px 14px; border-radius: 6px; background: #fff3cd; color: #664d03; font-size: 14px; }
        #preview-note.completed { background: #d1e7dd; color: #0f5132; }
        #preview-message { max-width: 900px; margin: 40px auto; padding: 16px; background: #f8d7da; color: #58151c; border-radius: 6px; }
    </style>
</head>
<body>
<?php if ($message !== null): ?>
    <div id="preview-message"><?= $e($message) ?></div>
<?php else: ?>
    <div id="preview-note"><?= $e(M::get('preview.note', 'Vorschau: Eingaben werden nicht gespeichert, „Abschicken" sendet nichts.')) ?></div>
    <div id="surveyContainer"></div>

    <script nonce="<?= $e($nonce) ?>" src="assets/preview/survey.core.min.js"></script>
    <script nonce="<?= $e($nonce) ?>" src="assets/preview/survey-js-ui.min.js"></script>
    <script nonce="<?= $e($nonce) ?>" src="assets/preview/survey-handler-base.js"></script>
    <script nonce="<?= $e($nonce) ?>">
        window.previewConfig = {
            survey: <?= $preview['survey_json'] ?>,
            theme: <?= $preview['theme_json'] ?>,
            completedNote: <?= json_encode(M::get('preview.completed', 'Vorschau: Das Formular wurde nicht abgeschickt, es wird nichts gespeichert.'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?>
        };
    </script>
    <script nonce="<?= $e($nonce) ?>" src="assets/preview/survey-preview.js"></script>
<?php endif; ?>
</body>
</html>
