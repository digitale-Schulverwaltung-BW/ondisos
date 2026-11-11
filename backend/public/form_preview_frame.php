<?php
// public/form_preview_frame.php
// The sandboxed document of the survey preview: renders a survey (draft or published) in the SurveyJS runtime.
// Shown inside form_preview.php and, with live=1, inside the live preview window (form_preview_live.php); never opened
// directly by a link in the admin. The markup is in inc/preview_frame.php.
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
// Live preview (AP4a, form_preview_live.php): the survey comes from the editor by postMessage, after the server checked
// it (form_preview_check.php); the frame itself only brings the theme.
$live    = ($_GET['live'] ?? '') === '1';

$controller = new SurveyPreviewController($configs, $resources, $drafts);
$preview    = $live ? ['survey_json' => null] + $controller->loadTheme($formKey) : $controller->load($formKey, $source);

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

$frameSurveyJson = $preview['survey_json'];
$frameThemeJson  = $preview['theme_json'];
$frameLive       = $live;
require __DIR__ . '/../inc/preview_frame.php';
