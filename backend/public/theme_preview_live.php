<?php
// public/theme_preview_live.php
// Live preview of the theme editor (3.2.0b AP3, docs/entwicklung/plans/PLAN-3.2.0b.md): a window of its own, opened by
// the "Live-Vorschau" button of theme_edit.php. It shows the published survey of a form that uses the theme, with the
// theme text from the editor, unsaved, and follows every change. Every text is checked by the server
// (theme_preview_check.php) before the sandboxed frame (form_preview_frame.php?theme=1) applies it. Nothing is stored.

declare(strict_types=1);

require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/auth.php';
require_once __DIR__ . '/../inc/csrf.php';
require_once __DIR__ . '/../inc/form_editor.php';
require_once __DIR__ . '/../inc/form_fields.php';

use App\Controllers\SurveyPreviewController;
use App\Services\MessageService as M;
use App\Utils\ContentSecurityPolicy;

$name = is_string($_GET['name'] ?? null) ? $_GET['name'] : '';

ContentSecurityPolicy::send();
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex');

$theme = $editorNoTenant ? null : $themeEditor->load($name);
if ($theme === null) {
    http_response_code(404);
}

// The forms that use this theme; only those with a survey in the backend can be shown (the frame renders stored surveys).
$forms = [];
$selected = null;
if ($theme !== null) {
    $previews = new SurveyPreviewController($configs, $resources, $drafts);
    $wanted   = is_string($_GET['form'] ?? null) ? $_GET['form'] : null;
    foreach ($theme['forms'] as $key) {
        $hasSurvey = $previews->load($key, SurveyPreviewController::SOURCE_LIVE)['has_live'];
        $forms[] = ['key' => $key, 'has_survey' => $hasSurvey];
        if ($hasSurvey && ($selected === null || $key === $wanted)) {
            $selected = $key;
        }
    }
}
?><!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= ff_e(M::get('live_preview.theme_title', 'Live-Vorschau Theme')) ?> · <?= ff_e($name) ?></title>
    <link href="assets/bootstrap/bootstrap.min.css" rel="stylesheet">
    <link href="assets/survey-builder/live-window.css" rel="stylesheet">
</head>
<body class="lp-body">
<?php if ($theme === null): ?>
    <div class="alert alert-danger m-3"><?= ff_e($editorNoTenant
        ? M::get('forms.choose_tenant', 'Bitte oben rechts einen Tenant wählen: Formulare gehören immer zu genau einer Schule.')
        : M::get('theme_editor.not_found', 'Dieses Theme gibt es nicht. Neue Themes entstehen, indem ein Formular sie in seiner Konfiguration nennt.')) ?></div>
<?php else: ?>
    <?php
    $liveMode         = 'theme';
    $liveForm         = $name;
    $liveForms        = $forms;
    $liveSelectedForm = $selected;
    $liveFrameBase    = 'form_preview_frame.php?theme=1&form=';
    $liveFrameUrl     = $liveFrameBase . urlencode((string)$selected);
    $liveCheckUrl     = 'theme_preview_check.php';
    $liveCsrf         = csrf_token();
    $liveThemeMissing = false;
    require __DIR__ . '/../inc/live_preview_window.php';
    ?>
    <script type="module" src="assets/survey-builder/live-window.js"></script>
<?php endif; ?>
</body>
</html>
