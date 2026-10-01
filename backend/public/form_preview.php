<?php
// public/form_preview.php
// Survey preview: shows the draft or the published survey of a form as visitors would see it (nothing is saved).
// The survey itself is rendered in form_preview_frame.php inside a sandboxed iframe.

declare(strict_types=1);

require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/auth.php';
require_once __DIR__ . '/../inc/csrf.php';
require_once __DIR__ . '/../inc/form_editor.php';
require_once __DIR__ . '/../inc/form_fields.php';

use App\Controllers\SurveyPreviewController;
use App\Services\MessageService as M;

$formKey = is_string($_GET['form'] ?? null) ? $_GET['form'] : '';

if ($editorNoTenant) {
    editor_flash('info', M::get('forms.choose_tenant', 'Bitte oben rechts einen Tenant wählen: Formulare gehören immer zu genau einer Schule.'));
    header('Location: forms.php');
    exit;
}

$requested = ($_GET['source'] ?? '') === SurveyPreviewController::SOURCE_LIVE ? SurveyPreviewController::SOURCE_LIVE : SurveyPreviewController::SOURCE_DRAFT;
$preview   = (new SurveyPreviewController($configs, $resources, $drafts))->load($formKey, $requested);

if ($preview['status'] === 'not_found') {
    http_response_code(404);
    require __DIR__ . '/../inc/header.php';
    echo '<div class="container mt-4"><div class="alert alert-danger">' . ff_e(M::get('forms.not_found', 'Dieses Formular gibt es nicht.')) . '</div>'
       . '<a href="forms.php" class="btn btn-secondary">' . ff_e(M::get('forms.back', '← Zurück zu den Formularen')) . '</a></div>';
    require __DIR__ . '/../inc/footer.php';
    exit;
}

$self = 'form_preview.php?form=' . urlencode($formKey);
$shown = $preview['source']; // what is actually rendered (draft falls back to live)

require __DIR__ . '/../inc/header.php';
?>

<div class="container-fluid mt-4 mb-5" style="max-width: 1100px;">
    <nav aria-label="breadcrumb" class="mb-3">
        <ol class="breadcrumb">
            <li class="breadcrumb-item"><a href="forms.php"><?= ff_e(M::get('forms.title', 'Formulare')) ?></a></li>
            <li class="breadcrumb-item"><a href="form_edit.php?form=<?= urlencode($formKey) ?>"><code><?= ff_e($formKey) ?></code></a></li>
            <li class="breadcrumb-item"><a href="form_survey.php?form=<?= urlencode($formKey) ?>"><?= ff_e(M::get('survey_editor.breadcrumb', 'Survey')) ?></a></li>
            <li class="breadcrumb-item active"><?= ff_e(M::get('preview.title', 'Vorschau')) ?></li>
        </ol>
    </nav>

    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
        <h1 class="h3 mb-0"><?= ff_e(M::format('preview.heading', ['form' => $formKey], 'Vorschau von „{{form}}"')) ?></h1>
        <a href="form_survey.php?form=<?= urlencode($formKey) ?>" class="btn btn-outline-primary btn-sm"><?= ff_e(M::get('preview.back_to_editor', 'Zurück zum Survey-Editor')) ?></a>
    </div>

    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
        <div class="btn-group" role="group" aria-label="<?= ff_e(M::get('preview.source', 'Welche Fassung')) ?>">
            <a class="btn btn-sm <?= $shown === 'draft' ? 'btn-primary' : 'btn-outline-primary' ?><?= $preview['has_draft'] ? '' : ' disabled' ?>"
               href="<?= ff_e($self) ?>&source=draft"><?= ff_e(M::get('preview.draft', 'Entwurf')) ?></a>
            <a class="btn btn-sm <?= $shown === 'live' ? 'btn-primary' : 'btn-outline-primary' ?><?= $preview['has_live'] ? '' : ' disabled' ?>"
               href="<?= ff_e($self) ?>&source=live"><?= ff_e(M::get('preview.live', 'Veröffentlicht')) ?></a>
        </div>
        <div class="btn-group" role="group" aria-label="<?= ff_e(M::get('preview.width', 'Breite')) ?>" id="width-buttons">
            <button type="button" class="btn btn-sm btn-outline-secondary" data-width="390"><?= ff_e(M::get('preview.phone', 'Handy')) ?></button>
            <button type="button" class="btn btn-sm btn-outline-secondary" data-width="820"><?= ff_e(M::get('preview.tablet', 'Tablet')) ?></button>
            <button type="button" class="btn btn-sm btn-outline-secondary active" data-width="100%"><?= ff_e(M::get('preview.desktop', 'Desktop')) ?></button>
        </div>
    </div>

    <?php if ($shown === 'draft' && $preview['has_live']): ?>
        <div class="alert alert-warning py-2"><?= ff_e(M::get('preview.showing_draft', 'Gezeigt wird der Entwurf. Besucher sehen noch die veröffentlichte Fassung.')) ?></div>
    <?php elseif ($shown === 'live'): ?>
        <div class="alert alert-secondary py-2"><?= ff_e(M::get('preview.showing_live', 'Gezeigt wird die veröffentlichte Fassung.')) ?></div>
    <?php endif; ?>

    <?php if ($preview['status'] === 'invalid'): ?>
        <div class="alert alert-danger">
            <strong><?= ff_e(M::get('preview.invalid_title', 'Die Vorschau wird nicht angezeigt.')) ?></strong>
            <?= ff_e(M::get('preview.invalid', 'Diese Survey enthält Inhalte, die nicht angezeigt werden dürfen. Bitte im Survey-Editor korrigieren:')) ?>
            <ul class="mb-0">
                <?php foreach (array_slice($preview['errors'], 0, 8) as $err): ?>
                    <li><?= ff_e(($err['path'] !== '' ? $err['path'] . ': ' : '') . $err['message']) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php elseif ($preview['status'] === 'nothing'): ?>
        <div class="alert alert-info">
            <?= ff_e(M::get('preview.nothing', 'Zu diesem Formular gibt es im Backend noch keine Survey, die sich anzeigen ließe (sie liegt als Datei im Frontend).')) ?>
            <a href="form_survey.php?form=<?= urlencode($formKey) ?>"><?= ff_e(M::get('preview.to_editor', 'Survey im Editor einfügen')) ?></a>
        </div>
    <?php else: ?>
        <?php foreach ($preview['warnings'] as $w): ?>
            <div class="alert alert-warning py-2 mb-2"><?= ff_e(($w['path'] !== '' ? $w['path'] . ': ' : '') . $w['message']) ?></div>
        <?php endforeach; ?>
        <?php if (!$preview['theme_from_backend']): ?>
            <div class="alert alert-light border py-2"><?= ff_e(M::get('preview.no_theme', 'Das Theme (Farben, Schrift) liegt als Datei im Frontend und fehlt in der Vorschau. Das Formular sieht für Besucher also etwas anders aus.')) ?></div>
        <?php endif; ?>

        <?php // The frame document is sandboxed by its own Content-Security-Policy header (see form_preview_frame.php). ?>
        <div class="border rounded bg-light p-2 text-center" style="overflow-x: auto;">
            <iframe id="preview-frame" title="<?= ff_e(M::get('preview.title', 'Vorschau')) ?>"
                    src="form_preview_frame.php?form=<?= urlencode($formKey) ?>&source=<?= ff_e($shown) ?>"
                    style="width: 100%; height: 78vh; border: 0; background: #f5f5f5; margin: 0 auto; display: block;"></iframe>
        </div>
    <?php endif; ?>
</div>

<script>
(function () {
    var frame = document.getElementById('preview-frame');
    var group = document.getElementById('width-buttons');
    if (!frame || !group) { return; }
    group.querySelectorAll('button').forEach(function (btn) {
        btn.addEventListener('click', function () {
            group.querySelectorAll('button').forEach(function (b) { b.classList.remove('active'); });
            btn.classList.add('active');
            var w = btn.getAttribute('data-width');
            frame.style.width = w.indexOf('%') > -1 ? w : w + 'px';
        });
    });
})();
</script>
<?php require __DIR__ . '/../inc/footer.php'; ?>
