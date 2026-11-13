<?php
// public/form_preview_live.php
// Live preview of the survey editor (AP4a, docs/entwicklung/plans/PLAN-3.2.0.md): a window of its own, opened by the
// "Live-Vorschau" button of form_survey.php. It shows the text in the editor as visitors would see it, unsaved, and follows
// every change. Every text is checked by the server (form_preview_check.php) before the sandboxed frame
// (form_preview_frame.php?live=1) renders it. Nothing is stored.

declare(strict_types=1);

require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/auth.php';
require_once __DIR__ . '/../inc/csrf.php';
require_once __DIR__ . '/../inc/form_editor.php';
require_once __DIR__ . '/../inc/form_fields.php';

use App\Controllers\SurveyPreviewController;
use App\Services\MessageService as M;
use App\Utils\ContentSecurityPolicy;

$formKey = is_string($_GET['form'] ?? null) ? $_GET['form'] : '';

ContentSecurityPolicy::send();
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex');

$theme = $editorNoTenant ? ['status' => 'not_found'] : (new SurveyPreviewController($configs, $resources, $drafts))->loadTheme($formKey);
if ($theme['status'] !== 'ok') {
    http_response_code(404);
}
?><!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= ff_e(M::get('live_preview.title', 'Live-Vorschau')) ?> · <?= ff_e($formKey) ?></title>
    <link href="assets/bootstrap/bootstrap.min.css" rel="stylesheet">
    <?php $assets = \App\Utils\AssetVersion::forPublic(); ?>
    <link href="<?= $assets->url('assets/survey-builder/live-window.css') ?>" rel="stylesheet">
</head>
<body class="lp-body">
<?php if ($theme['status'] !== 'ok'): ?>
    <div class="alert alert-danger m-3"><?= ff_e($editorNoTenant
        ? M::get('forms.choose_tenant', 'Bitte oben rechts einen Tenant wählen: Formulare gehören immer zu genau einer Schule.')
        : M::get('forms.not_found', 'Dieses Formular gibt es nicht.')) ?></div>
<?php else: ?>
    <?php
    $liveForm         = $formKey;
    $liveFrameUrl     = 'form_preview_frame.php?form=' . urlencode($formKey) . '&live=1';
    $liveCheckUrl     = 'form_preview_check.php';
    $liveCsrf         = csrf_token();
    $liveThemeMissing = !$theme['theme_from_backend'];
    require __DIR__ . '/../inc/live_preview_window.php';
    ?>
    <?= $assets->importMapTag(['assets/survey-builder'], \App\Utils\ContentSecurityPolicy::nonce()) ?>
    <script type="module" src="<?= $assets->url('assets/survey-builder/live-window.js') ?>"></script>
<?php endif; ?>
</body>
</html>
