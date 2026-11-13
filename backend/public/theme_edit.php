<?php
// public/theme_edit.php
// Theme editor (3.2.0b): edit a theme with fields (AP4) or as JSON, check it, publish it (with optimistic locking), restore from history.
// There is no stored draft: unsaved text lives in the page until it is published (decision 3 of PLAN-3.2.0b).

declare(strict_types=1);

require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/auth.php';
require_once __DIR__ . '/../inc/csrf.php';
require_once __DIR__ . '/../inc/form_editor.php';
require_once __DIR__ . '/../inc/form_fields.php';
require_once __DIR__ . '/../inc/editor_diff.php';

use App\Services\MessageService as M;

$name = is_string($_GET['name'] ?? null) ? $_GET['name'] : '';
$self = 'theme_edit.php?name=' . urlencode($name);

if ($editorNoTenant) {
    editor_flash('info', M::get('forms.choose_tenant', 'Bitte oben rechts einen Tenant wählen: Formulare gehören immer zu genau einer Schule.'));
    header('Location: forms.php');
    exit;
}

/** @var array<string,mixed>|null $report result of a check shown above the editor */
$report     = null;
$postedText = null;   // text the admin sent; shown again instead of the stored one
$postedSha  = null;   // sha256 the editor was opened with (optimistic lock), carried across checks
$conflict   = false;
$notice     = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        csrf_validate();
    } catch (\InvalidArgumentException $e) {
        editor_flash('danger', M::get('forms.csrf_failed', 'Die Sitzung ist abgelaufen. Bitte die Seite neu laden und erneut versuchen.'));
        header('Location: ' . $self);
        exit;
    }

    $action    = (string)($_POST['action'] ?? '');
    $text      = is_string($_POST['theme_text'] ?? null) ? $_POST['theme_text'] : '';
    $postedSha = is_string($_POST['based_on'] ?? null) ? $_POST['based_on'] : '';

    switch ($action) {
        case 'check':
            $postedText = $text;
            $report     = $themeEditor->check($name, $text);
            break;

        case 'publish':
            $out = $themeEditor->publish($name, $text, $postedSha, is_string($_POST['note'] ?? null) ? $_POST['note'] : null);
            if ($out['status'] === 'published' || $out['status'] === 'unchanged') {
                editor_flash('success', $out['status'] === 'published'
                    ? M::get('theme_editor.published', 'Veröffentlicht: Die Formulare zeigen ab sofort dieses Design.')
                    : M::get('theme_editor.unchanged', 'Keine Änderung gegenüber der veröffentlichten Fassung; es wurde nichts gespeichert.'));
                header('Location: ' . $self);
                exit;
            }
            $conflict   = $out['status'] === 'conflict';
            $postedText = $text;
            $report     = $out['report'];
            break;

        case 'load_default':
            // Only fills the editor; nothing is stored until the admin publishes.
            $postedText = $themeEditor->defaultTheme();
            $report     = $themeEditor->check($name, $postedText);
            $notice     = ['info', M::get('theme_editor.default_loaded', 'Die eingebaute Vorgabe steht jetzt im Editor. Gespeichert ist noch nichts: erst „Veröffentlichen“ übernimmt sie.')];
            break;

        case 'restore':
            $out = $themeEditor->restore($name, (int)($_POST['revision'] ?? 0));
            if ($out['status'] === 'restored' || $out['status'] === 'unchanged') {
                editor_flash('success', $out['status'] === 'restored'
                    ? M::get('forms.restored', 'Der frühere Stand wurde wiederhergestellt.')
                    : M::get('theme_editor.restore_unchanged', 'Dieser Stand ist bereits veröffentlicht.'));
            } else {
                foreach ($out['result']->errors() as $e) {
                    editor_flash('danger', $e['message']);
                }
                if ($out['status'] === 'not_found') {
                    editor_flash('danger', M::get('forms.revision_not_found', 'Diesen Stand gibt es nicht.'));
                }
            }
            header('Location: ' . $self);
            exit;
    }
}

$view = $themeEditor->load($name);
if ($view === null) {
    http_response_code(404);
    require __DIR__ . '/../inc/header.php';
    echo '<div class="container mt-4"><div class="alert alert-danger">' . ff_e(M::get('theme_editor.not_found', 'Dieses Theme gibt es nicht. Neue Themes entstehen, indem ein Formular sie in seiner Konfiguration nennt.')) . '</div>'
       . '<a href="themes.php" class="btn btn-secondary">' . ff_e(M::get('theme_editor.back', '← Zurück zur Theme-Liste')) . '</a></div>';
    require __DIR__ . '/../inc/footer.php';
    exit;
}

$editorText = $postedText ?? $view['content'];
// The lock stays at the state the editor was opened with; only after a conflict does the page move on to the new
// live state (the admin has seen the difference and publishes again on purpose).
$basedOn = ($postedSha !== null && !$conflict) ? $postedSha : (string)($view['sha256'] ?? '');
// Live preview (AP3): one window per tenant and theme, so a second click brings the same window to the front.
$livePreviewUrl    = 'theme_preview_live.php?name=' . urlencode($view['name']);
$livePreviewWindow = 'ondisos-theme-' . \App\Config\TenantContext::getTenantId() . '-' . $view['name'];
$formsText = $view['forms'] !== [] ? implode(', ', $view['forms']) : M::get('theme_editor.unused', 'kein Formular');

/** Link text for a finding: "Zeile 12" (jumps to the line in the editor via survey-editor.js) */
function te_line(?int $line): string
{
    if ($line === null) {
        return '';
    }
    return ' <a href="#survey-text" class="se-goto" data-line="' . $line . '">' . ff_e(M::format('survey_editor.line', ['line' => $line], 'Zeile {{line}}')) . '</a>';
}

require __DIR__ . '/../inc/header.php';
?>

<div class="container mt-4 mb-5" id="te-page">
    <nav aria-label="breadcrumb" class="mb-3">
        <ol class="breadcrumb">
            <li class="breadcrumb-item"><a href="forms.php"><?= ff_e(M::get('forms.title', 'Formulare')) ?></a></li>
            <li class="breadcrumb-item"><a href="themes.php"><?= ff_e(M::get('theme_editor.list_title', 'Design (Themes)')) ?></a></li>
            <li class="breadcrumb-item active"><code><?= ff_e($view['name']) ?></code></li>
        </ol>
    </nav>

    <h1 class="mb-3"><?= ff_e(M::format('theme_editor.title', ['name' => $view['name']], 'Theme „{{name}}"')) ?></h1>

    <?php foreach (editor_take_flash() as [$type, $text]): ?>
        <div class="alert alert-<?= ff_e($type) ?> alert-dismissible fade show"><?= ff_e($text) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
    <?php endforeach; ?>
    <?php if ($notice !== null): ?><div class="alert alert-<?= ff_e($notice[0]) ?>"><?= ff_e($notice[1]) ?></div><?php endif; ?>

    <?php if ($conflict): ?>
        <div class="alert alert-danger">
            <?= ff_e(M::get('theme_editor.conflict', 'Das Theme wurde inzwischen von jemand anderem veröffentlicht. Ihre Änderung wurde nicht gespeichert. Bitte den Unterschied unten prüfen und erneut veröffentlichen.')) ?>
        </div>
    <?php endif; ?>

    <!-- Status ------------------------------------------------------------------------------------------------>
    <div class="card mb-4">
        <div class="card-body">
            <?php if ($view['exists']): ?>
                <p class="mb-1"><span class="badge text-bg-success"><?= ff_e(M::get('survey_editor.live', 'Veröffentlicht')) ?></span>
                    <?php if ($view['updated_at']): ?>
                        <?= ff_e($view['updated_at']) ?><?= $view['updated_by'] ? ' · ' . ff_e($view['updated_by']) : '' ?>
                    <?php endif; ?>
                </p>
            <?php else: ?>
                <p class="mb-1"><span class="badge text-bg-secondary"><?= ff_e(M::get('theme_editor.status.missing', 'Noch nicht angelegt')) ?></span>
                    <?= ff_e(M::get('theme_editor.not_stored', 'Dieses Theme liegt noch nicht im Backend; die Formulare nutzen die Datei im Frontend, falls vorhanden. Der Editor zeigt die eingebaute Vorgabe. Mit der ersten Veröffentlichung übernimmt das Backend.')) ?></p>
            <?php endif; ?>
            <p class="mb-0"><?= ff_e(M::get('theme_editor.used_by', 'Genutzt von:')) ?>
                <?php if ($view['forms'] === []): ?>
                    <span class="text-muted"><?= ff_e(M::get('theme_editor.unused', 'kein Formular')) ?></span>
                <?php else: ?>
                    <?php foreach ($view['forms'] as $i => $f): ?><?= $i > 0 ? ', ' : '' ?><a href="form_edit.php?form=<?= urlencode($f) ?>"><code><?= ff_e($f) ?></code></a><?php endforeach; ?>
                <?php endif; ?>
            </p>
        </div>
    </div>

    <!-- Report ------------------------------------------------------------------------------------------------>
    <?php if ($report !== null): ?>
        <div class="card mb-4 <?= $report['valid'] ? 'border-success' : 'border-danger' ?>" id="te-report">
            <div class="card-header"><h5 class="mb-0"><?= ff_e($report['valid'] ? M::get('survey_editor.report.ok', 'Prüfung: keine Fehler') : M::get('survey_editor.report.errors', 'Prüfung: bitte korrigieren')) ?></h5></div>
            <div class="card-body">
                <?php if (!$report['valid']): ?>
                    <p class="mb-2"><?= ff_e(M::get('survey_editor.report.nothing_saved', 'Es wurde nichts gespeichert.')) ?></p>
                    <ul class="mb-3">
                        <?php foreach ($report['errors'] as $e): ?>
                            <li class="text-danger"><?= ff_e(($e['path'] !== '' ? $e['path'] . ': ' : '') . $e['message']) ?><?= te_line($e['line']) ?>
                                <?= $e['column'] !== null ? ' ' . ff_e(M::format('survey_editor.column', ['column' => $e['column']], '(Spalte {{column}})')) : '' ?></li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>

                <?php if ($report['warnings'] !== []): ?>
                    <h6><?= ff_e(M::get('survey_editor.report.warnings', 'Hinweise')) ?></h6>
                    <ul class="mb-3">
                        <?php foreach ($report['warnings'] as $w): ?>
                            <li class="text-warning-emphasis"><?= ff_e(($w['path'] !== '' ? $w['path'] . ': ' : '') . $w['message']) ?><?= te_line($w['line']) ?></li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>

                <?php if ($report['valid']): ?>
                    <p class="mb-0"><?= ff_e(M::format('theme_editor.affects', ['forms' => $formsText], 'Die Änderung betrifft: {{forms}}')) ?></p>
                    <?php if ($report['diff'] !== null): ?>
                        <?= editor_diff_html($report['diff']) ?>
                    <?php elseif ($report['first_publish']): ?>
                        <p class="text-muted mt-2 mb-0"><?= ff_e(M::get('theme_editor.first_publish', 'Erste Veröffentlichung: Ab dann liefert das Backend dieses Theme aus.')) ?></p>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>

    <!-- Editor ------------------------------------------------------------------------------------------------>
    <form method="post" action="<?= ff_e($self) ?>" id="survey-form">
        <?php csrf_field(); ?>
        <input type="hidden" name="based_on" value="<?= ff_e($basedOn) ?>">
        <?php require __DIR__ . '/../inc/theme_fields.php'; // the usual settings as fields, written into the JSON (AP4) ?>

        <div class="mb-2 d-flex justify-content-between align-items-end">
            <label class="form-label mb-0" for="survey-text"><?= ff_e(M::get('theme_editor.editor', 'Theme (JSON)')) ?></label>
            <div class="d-flex gap-2">
                <button type="button" class="btn btn-sm btn-outline-primary" id="te-live-preview" hidden
                        data-url="<?= ff_e($livePreviewUrl) ?>" data-window="<?= ff_e($livePreviewWindow) ?>" data-form="<?= ff_e($view['name']) ?>"
                        title="<?= ff_e(M::get('theme_editor.live_help', 'Zeigt ein Formular, das dieses Theme nutzt, in einem eigenen Fenster mit dem Theme aus dem Editor. Es folgt jeder Änderung, auch ungespeichert.')) ?>"><?= ff_e(M::get('live_preview.open', 'Live-Vorschau')) ?> ↗</button>
                <button type="button" class="btn btn-sm btn-outline-secondary" id="se-copy"><?= ff_e(M::get('survey_editor.copy', 'JSON kopieren')) ?></button>
            </div>
        </div>
        <textarea id="survey-text" name="theme_text" class="form-control font-monospace" rows="26" spellcheck="false" wrap="off"
                  <?= $postedText !== null && trim($postedText) !== trim($view['content']) ? 'data-unsaved="1"' : '' ?>
                  style="font-size: .8rem; white-space: pre;"><?= ff_e($editorText) ?></textarea>
        <div class="form-text mb-3"><?= ff_e(M::get('theme_editor.editor_help', 'Ein Theme aus dem SurveyJS Theme Editor (Website, Reiter „JSON“) hier einfügen oder den Text direkt ändern. Beim Prüfen wird nichts gespeichert.')) ?></div>

        <div class="d-flex flex-wrap gap-2 mb-4">
            <button type="submit" name="action" value="check" class="btn btn-outline-primary"><?= ff_e(M::get('survey_editor.btn.check', 'Prüfen')) ?></button>
            <button type="submit" name="action" value="load_default" class="btn btn-outline-secondary"
                    data-confirm="<?= ff_e(M::get('theme_editor.default_confirm', 'Den Text im Editor durch die eingebaute Vorgabe ersetzen? Gespeichert wird dabei nichts.')) ?>"><?= ff_e(M::get('theme_editor.btn.default', 'Auf Vorgabe zurücksetzen')) ?></button>
        </div>

        <div class="card mb-4">
            <div class="card-header"><h5 class="mb-0"><?= ff_e(M::get('survey_editor.publish.title', 'Veröffentlichen')) ?></h5></div>
            <div class="card-body">
                <p class="text-muted"><?= ff_e(M::get('theme_editor.publish.help', 'Der Text aus dem Editor wird geprüft, gespeichert und sofort in allen Formularen sichtbar, die dieses Theme nutzen. Der bisherige Stand bleibt im Verlauf und lässt sich wiederherstellen.')) ?></p>
                <p><strong><?= ff_e(M::format('theme_editor.affects', ['forms' => $formsText], 'Die Änderung betrifft: {{forms}}')) ?></strong></p>
                <div class="mb-3">
                    <label class="form-label" for="note"><?= ff_e(M::get('survey_editor.publish.note', 'Anmerkung für den Verlauf (optional)')) ?></label>
                    <input type="text" class="form-control" id="note" name="note" maxlength="255">
                </div>
                <button type="submit" name="action" value="publish" class="btn btn-success"
                        data-confirm="<?= ff_e(M::format('theme_editor.publish.confirm', ['forms' => $formsText], 'Jetzt veröffentlichen? Das Design ändert sich sofort in diesen Formularen: {{forms}}')) ?>"><?= ff_e(M::get('survey_editor.btn.publish', 'Veröffentlichen')) ?></button>
            </div>
        </div>
    </form>

    <!-- History ----------------------------------------------------------------------------------------------->
    <?php if ($view['revisions'] !== []): ?>
    <div class="card mb-4">
        <div class="card-header"><h5 class="mb-0"><?= ff_e(M::get('forms.history.title', 'Verlauf')) ?></h5></div>
        <div class="table-responsive">
            <table class="table table-sm align-middle mb-0">
                <thead><tr>
                    <th><?= ff_e(M::get('forms.history.when', 'Wann')) ?></th>
                    <th><?= ff_e(M::get('forms.history.note', 'Anmerkung')) ?></th>
                    <th><?= ff_e(M::get('forms.history.who', 'Wer')) ?></th>
                    <th></th>
                </tr></thead>
                <tbody>
                <?php foreach ($view['revisions'] as $rev): ?>
                    <tr>
                        <td class="text-nowrap"><?= ff_e($rev['created_at']) ?></td>
                        <td><?= ff_e($rev['note'] ?? '') ?></td>
                        <td><?= ff_e($rev['created_by'] ?? '') ?></td>
                        <td class="text-end">
                            <?php if ($view['sha256'] !== null && hash_equals($view['sha256'], (string)$rev['sha256'])): ?>
                                <span class="badge text-bg-light border"><?= ff_e(M::get('theme_editor.history.current', 'aktuell')) ?></span>
                            <?php else: ?>
                            <form method="post" action="<?= ff_e($self) ?>" class="d-inline"
                                  data-confirm="<?= ff_e(M::format('theme_editor.history.confirm', ['forms' => $formsText], 'Diesen früheren Stand wiederherstellen? Das Design ändert sich sofort in diesen Formularen: {{forms}}. Der aktuelle Stand bleibt im Verlauf erhalten.')) ?>">
                                <?php csrf_field(); ?>
                                <input type="hidden" name="action" value="restore">
                                <input type="hidden" name="revision" value="<?= (int)$rev['id'] ?>">
                                <button type="submit" class="btn btn-sm btn-outline-secondary"><?= ff_e(M::get('forms.history.restore', 'Wiederherstellen')) ?></button>
                            </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>
</div>

<?php
// Findings for the code editor (marks them on their line).
$diagnostics = [];
if ($report !== null) {
    foreach ($report['errors'] as $e) {
        if ($e['line'] !== null) {
            $diagnostics[] = ['line' => $e['line'], 'message' => ($e['path'] !== '' ? $e['path'] . ': ' : '') . $e['message'], 'severity' => 'error'];
        }
    }
    foreach ($report['warnings'] as $w) {
        if ($w['line'] !== null) {
            $diagnostics[] = ['line' => $w['line'], 'message' => ($w['path'] !== '' ? $w['path'] . ': ' : '') . $w['message'], 'severity' => 'warning'];
        }
    }
}
?>
<script type="application/json" id="survey-diagnostics"><?= json_encode($diagnostics, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?></script>
<?php $assets = \App\Utils\AssetVersion::forPublic(); ?>
<?php if (is_file(__DIR__ . '/assets/codemirror/survey-editor-cm.js')): ?>
<script src="<?= $assets->url('assets/codemirror/survey-editor-cm.js') ?>"></script>
<?php endif; ?>
<script src="<?= $assets->url('assets/survey-editor.js') ?>"></script>
<?= $assets->importMapTag(['assets', 'assets/survey-builder', 'assets/theme-editor'], \App\Utils\ContentSecurityPolicy::nonce()) ?>
<script type="module" src="<?= $assets->url('assets/theme-editor.js') ?>"></script>
<?php require __DIR__ . '/../inc/footer.php'; ?>
