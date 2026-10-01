<?php
// public/form_survey.php
// Survey editor: paste the JSON from the SurveyJS Creator, check it, keep a draft, publish, restore.

declare(strict_types=1);

require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/auth.php';
require_once __DIR__ . '/../inc/csrf.php';
require_once __DIR__ . '/../inc/form_editor.php';
require_once __DIR__ . '/../inc/form_fields.php';

use App\Forms\SurveyValidator;
use App\Services\MessageService as M;

$formKey = is_string($_GET['form'] ?? null) ? $_GET['form'] : '';
$self    = 'form_survey.php?form=' . urlencode($formKey);

if ($editorNoTenant) {
    editor_flash('info', M::get('forms.choose_tenant', 'Bitte oben rechts einen Tenant wählen: Formulare gehören immer zu genau einer Schule.'));
    header('Location: forms.php');
    exit;
}

/** @var array<string,mixed>|null $report result of a check shown below the editor */
$report       = null;
$postedText   = null;   // text the admin sent; shown again instead of the stored one
$conflict     = false;
$uploadNotice = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        csrf_validate();
    } catch (\InvalidArgumentException $e) {
        editor_flash('danger', M::get('forms.csrf_failed', 'Die Sitzung ist abgelaufen. Bitte die Seite neu laden und erneut versuchen.'));
        header('Location: ' . $self);
        exit;
    }

    $action = (string)($_POST['action'] ?? '');
    $text   = is_string($_POST['survey_text'] ?? null) ? $_POST['survey_text'] : '';

    switch ($action) {
        case 'check':
            $postedText = $text;
            $report     = $surveyEditor->check($formKey, $text);
            break;

        case 'save_draft':
            $out = $surveyEditor->saveDraft($formKey, $text);
            if ($out['status'] === 'saved') {
                editor_flash('success', M::get('survey_editor.draft_saved', 'Entwurf gespeichert. Das veröffentlichte Formular ist unverändert.'));
                header('Location: ' . $self);
                exit;
            }
            $postedText = $text;
            $report     = $out['report'];
            break;

        case 'publish':
            $out = $surveyEditor->publish($formKey, $text, is_string($_POST['new_version'] ?? null) ? $_POST['new_version'] : null, is_string($_POST['note'] ?? null) ? $_POST['note'] : null);
            if ($out['status'] === 'published') {
                editor_flash('success', M::get('survey_editor.published', 'Veröffentlicht: das Formular zeigt ab sofort diese Fassung.'));
                if ($out['version_error'] !== null) {
                    editor_flash('warning', M::get('survey_editor.version_not_saved', 'Die Version konnte nicht geändert werden:') . ' ' . $out['version_error']);
                }
                header('Location: ' . $self);
                exit;
            }
            $conflict   = $out['status'] === 'conflict';
            $postedText = $text;
            $report     = $out['report'];
            break;

        case 'discard':
            $surveyEditor->discard($formKey);
            editor_flash('success', M::get('survey_editor.draft_discarded', 'Der Entwurf wurde verworfen.'));
            header('Location: ' . $self);
            exit;

        case 'restore':
            $out = $surveyEditor->restore($formKey, (int)($_POST['revision'] ?? 0));
            if ($out['status'] === 'restored') {
                editor_flash('success', M::get('forms.restored', 'Der frühere Stand wurde wiederhergestellt.'));
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

        case 'upload':
            $file = $_FILES['survey_file'] ?? null;
            if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file((string)$file['tmp_name'])) {
                $uploadNotice = ['danger', M::get('survey_editor.upload_failed', 'Die Datei konnte nicht gelesen werden.')];
            } elseif ((int)$file['size'] > SurveyValidator::MAX_BYTES) {
                $uploadNotice = ['danger', M::get('survey_editor.upload_too_big', 'Die Datei ist zu groß (höchstens 512 KB).')];
            } else {
                // The file only fills the editor; nothing is stored until the admin saves a draft.
                $postedText   = (string)file_get_contents((string)$file['tmp_name']);
                $report       = $surveyEditor->check($formKey, $postedText);
                $uploadNotice = ['info', M::get('survey_editor.upload_loaded', 'Die Datei wurde in den Editor geladen und noch nicht gespeichert.')];
            }
            break;
    }
}

$view = $surveyEditor->load($formKey);
if ($view === null) {
    http_response_code(404);
    require __DIR__ . '/../inc/header.php';
    echo '<div class="container mt-4"><div class="alert alert-danger">' . ff_e(M::get('forms.not_found', 'Dieses Formular gibt es nicht.')) . '</div>'
       . '<a href="forms.php" class="btn btn-secondary">' . ff_e(M::get('forms.back', '← Zurück zu den Formularen')) . '</a></div>';
    require __DIR__ . '/../inc/footer.php';
    exit;
}

$editorText = $postedText ?? $view['editor_text'];

/** Link text for a finding: "Zeile 12" (jumps to the line in the editor via survey-editor.js) */
function se_line(?int $line): string
{
    if ($line === null) {
        return '';
    }
    return ' <a href="#survey-text" class="se-goto" data-line="' . $line . '">' . ff_e(M::format('survey_editor.line', ['line' => $line], 'Zeile {{line}}')) . '</a>';
}

require __DIR__ . '/../inc/header.php';
?>

<div class="container mt-4 mb-5">
    <nav aria-label="breadcrumb" class="mb-3">
        <ol class="breadcrumb">
            <li class="breadcrumb-item"><a href="forms.php"><?= ff_e(M::get('forms.title', 'Formulare')) ?></a></li>
            <li class="breadcrumb-item"><a href="form_edit.php?form=<?= urlencode($formKey) ?>"><code><?= ff_e($formKey) ?></code></a></li>
            <li class="breadcrumb-item active"><?= ff_e(M::get('survey_editor.breadcrumb', 'Survey')) ?></li>
        </ol>
    </nav>

    <h1 class="mb-3"><?= ff_e(M::format('survey_editor.title', ['form' => $formKey], 'Survey von „{{form}}"')) ?></h1>

    <?php foreach (editor_take_flash() as [$type, $text]): ?>
        <div class="alert alert-<?= ff_e($type) ?> alert-dismissible fade show"><?= ff_e($text) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
    <?php endforeach; ?>
    <?php if ($uploadNotice !== null): ?><div class="alert alert-<?= ff_e($uploadNotice[0]) ?>"><?= ff_e($uploadNotice[1]) ?></div><?php endif; ?>

    <?php if ($conflict): ?>
        <div class="alert alert-danger">
            <?= ff_e(M::get('survey_editor.conflict', 'Die veröffentlichte Survey wurde inzwischen von jemand anderem geändert. Ihr Entwurf wurde gespeichert, aber nicht veröffentlicht. Bitte den Unterschied unten prüfen und erneut veröffentlichen.')) ?>
        </div>
    <?php endif; ?>

    <!-- Status ------------------------------------------------------------------------------------------------>
    <div class="card mb-4">
        <div class="card-body">
            <p class="mb-1"><?= ff_e(M::get('forms.survey.file', 'Datei')) ?>: <code><?= ff_e($view['survey_name'] ?? '–') ?></code></p>
            <?php if ($view['live'] !== null): ?>
                <p class="mb-1"><span class="badge text-bg-success"><?= ff_e(M::get('survey_editor.live', 'Veröffentlicht')) ?></span>
                    <?= ff_e(M::format('forms.survey.fields', ['count' => count($view['live']['fields'])], '{{count}} Felder')) ?>
                    <?php if ($view['live']['updated_at']): ?>
                        · <?= ff_e($view['live']['updated_at']) ?><?= $view['live']['updated_by'] ? ' · ' . ff_e($view['live']['updated_by']) : '' ?>
                    <?php endif; ?>
                </p>
            <?php else: ?>
                <p class="mb-1"><span class="badge text-bg-secondary"><?= ff_e(M::get('forms.source.file', 'Datei im Frontend')) ?></span>
                    <?= ff_e(M::get('survey_editor.not_in_backend', 'Diese Survey ist noch nicht im Backend gespeichert: das Formular nutzt die Datei im Frontend. Mit der ersten Veröffentlichung übernimmt das Backend.')) ?></p>
            <?php endif; ?>
            <?php if ($view['draft'] !== null): ?>
                <p class="mb-0"><span class="badge text-bg-warning"><?= ff_e(M::get('forms.draft', 'Entwurf')) ?></span>
                    <?= ff_e($view['draft']['updated_at'] ?? '') ?><?= $view['draft']['updated_by'] ? ' · ' . ff_e($view['draft']['updated_by']) : '' ?>
                    <?php if ($view['draft']['stale']): ?>
                        <span class="text-danger"> — <?= ff_e(M::get('survey_editor.draft_stale', 'Die veröffentlichte Survey hat sich seit diesem Entwurf geändert.')) ?></span>
                    <?php endif; ?>
                </p>
            <?php endif; ?>
        </div>
    </div>

    <!-- Report ------------------------------------------------------------------------------------------------>
    <?php if ($report !== null): ?>
        <div class="card mb-4 <?= $report['valid'] ? 'border-success' : 'border-danger' ?>">
            <div class="card-header"><h5 class="mb-0"><?= ff_e($report['valid'] ? M::get('survey_editor.report.ok', 'Prüfung: keine Fehler') : M::get('survey_editor.report.errors', 'Prüfung: bitte korrigieren')) ?></h5></div>
            <div class="card-body">
                <?php if (!$report['valid']): ?>
                    <p class="mb-2"><?= ff_e(M::get('survey_editor.report.nothing_saved', 'Es wurde nichts gespeichert.')) ?></p>
                    <ul class="mb-3">
                        <?php foreach ($report['errors'] as $e): ?>
                            <li class="text-danger"><?= ff_e(($e['path'] !== '' ? $e['path'] . ': ' : '') . $e['message']) ?><?= se_line($e['line']) ?>
                                <?= $e['column'] !== null ? ' ' . ff_e(M::format('survey_editor.column', ['column' => $e['column']], '(Spalte {{column}})')) : '' ?></li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>

                <?php if ($report['warnings'] !== []): ?>
                    <h6><?= ff_e(M::get('survey_editor.report.warnings', 'Hinweise')) ?></h6>
                    <ul class="mb-3">
                        <?php foreach ($report['warnings'] as $w): ?>
                            <li class="text-warning-emphasis"><?= ff_e(($w['path'] !== '' ? $w['path'] . ': ' : '') . $w['message']) ?><?= se_line($w['line']) ?></li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>

                <?php if ($report['fields'] !== null): $fc = $report['fields']; ?>
                    <?php if ($fc['added'] || $fc['removed'] || $fc['type_changed']): ?>
                        <h6><?= ff_e(M::get('survey_editor.fields.title', 'Änderungen an den Feldern')) ?></h6>
                        <?php if ($fc['removed']): ?>
                            <div class="alert alert-warning py-2">
                                <strong><?= ff_e(M::get('survey_editor.fields.removed', 'Entfernte oder umbenannte Felder:')) ?></strong>
                                <?= ff_e(implode(', ', $fc['removed'])) ?><br>
                                <small><?= ff_e(M::get('survey_editor.fields.removed_hint', 'Diese Felder fehlen künftig in neuen Anmeldungen, im Excel-Export, in Vorausfüll-Links und im PDF. Bereits gespeicherte Anmeldungen behalten ihre Daten.')) ?></small>
                            </div>
                        <?php endif; ?>
                        <?php if ($fc['added']): ?>
                            <p class="mb-1"><strong><?= ff_e(M::get('survey_editor.fields.added', 'Neue Felder:')) ?></strong> <?= ff_e(implode(', ', $fc['added'])) ?></p>
                        <?php endif; ?>
                        <?php foreach ($fc['type_changed'] as $t): ?>
                            <p class="mb-1"><strong><?= ff_e(M::get('survey_editor.fields.type_changed', 'Anderer Typ:')) ?></strong> <?= ff_e($t['name']) ?>: <?= ff_e($t['from']) ?> → <?= ff_e($t['to']) ?></p>
                        <?php endforeach; ?>
                    <?php elseif ($report['valid']): ?>
                        <p class="text-muted mb-2"><?= ff_e(M::get('survey_editor.fields.none', 'Die Felder sind unverändert.')) ?></p>
                    <?php endif; ?>
                <?php endif; ?>

                <?php if ($report['valid'] && $report['diff'] !== null): $diff = $report['diff']; ?>
                    <details class="mt-3" <?= (!$diff['identical'] && !$diff['too_large']) ? 'open' : '' ?>>
                        <summary><?= ff_e(M::get('survey_editor.diff.title', 'Unterschied zur veröffentlichten Fassung')) ?>
                            <?php if (!$diff['identical'] && !$diff['too_large']): ?>
                                <span class="text-success">+<?= (int)$diff['added'] ?></span> <span class="text-danger">−<?= (int)$diff['removed'] ?></span>
                            <?php endif; ?></summary>
                        <?php if ($diff['identical']): ?>
                            <p class="text-muted mt-2"><?= ff_e(M::get('survey_editor.diff.identical', 'Keine Änderung gegenüber der veröffentlichten Fassung.')) ?></p>
                        <?php elseif ($diff['too_large']): ?>
                            <p class="text-muted mt-2"><?= ff_e(M::get('survey_editor.diff.too_large', 'Zu viele Änderungen für die Anzeige.')) ?></p>
                        <?php else: ?>
                            <div class="border rounded mt-2 small" style="max-height: 24rem; overflow: auto;">
                            <?php foreach ($diff['hunks'] as $n => $hunk): ?>
                                <?php if ($n > 0): ?><div class="text-muted px-2 bg-light">⋯</div><?php endif; ?>
                                <?php foreach ($hunk as $line): ?>
                                    <div class="font-monospace px-2 text-nowrap <?= $line['op'] === '+' ? 'bg-success-subtle' : ($line['op'] === '-' ? 'bg-danger-subtle' : '') ?>"><?= $line['op'] === ' ' ? '&nbsp;' : ff_e($line['op']) ?>&nbsp;<?= ff_e($line['text']) ?></div>
                                <?php endforeach; ?>
                            <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </details>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>

    <!-- Editor ------------------------------------------------------------------------------------------------>
    <form method="post" action="<?= ff_e($self) ?>" id="survey-form">
        <?php csrf_field(); ?>
        <div class="mb-2 d-flex justify-content-between align-items-end">
            <label class="form-label mb-0" for="survey-text"><?= ff_e(M::get('survey_editor.editor', 'Survey (JSON)')) ?></label>
            <button type="button" class="btn btn-sm btn-outline-secondary" id="se-copy"><?= ff_e(M::get('survey_editor.copy', 'JSON kopieren')) ?></button>
        </div>
        <textarea id="survey-text" name="survey_text" class="form-control font-monospace" rows="26" spellcheck="false" wrap="off"
                  style="font-size: .8rem; white-space: pre;"><?= ff_e($editorText) ?></textarea>
        <div class="form-text mb-3"><?= ff_e(M::get('survey_editor.editor_help', 'Hier das JSON aus dem SurveyJS-Creator einfügen (siehe Anleitung unten). Beim Prüfen und Speichern wird nichts veröffentlicht.')) ?></div>

        <div class="d-flex flex-wrap gap-2 mb-4">
            <button type="submit" name="action" value="check" class="btn btn-outline-primary"><?= ff_e(M::get('survey_editor.btn.check', 'Prüfen')) ?></button>
            <button type="submit" name="action" value="save_draft" class="btn btn-primary"><?= ff_e(M::get('survey_editor.btn.save_draft', 'Entwurf speichern')) ?></button>
        </div>

        <div class="card mb-4">
            <div class="card-header"><h5 class="mb-0"><?= ff_e(M::get('survey_editor.publish.title', 'Veröffentlichen')) ?></h5></div>
            <div class="card-body">
                <p class="text-muted"><?= ff_e(M::get('survey_editor.publish.help', 'Der Text aus dem Editor wird geprüft, gespeichert und sofort für alle Besucher sichtbar. Der bisherige Stand bleibt im Verlauf und lässt sich wiederherstellen. Änderungen wirken nur auf neue Anmeldungen.')) ?></p>
                <div class="row g-3 mb-3">
                    <div class="col-md-4">
                        <label class="form-label" for="new_version"><?= ff_e(M::get('survey_editor.publish.version', 'Neue Formular-Version (optional)')) ?></label>
                        <input type="text" class="form-control" id="new_version" name="new_version" maxlength="50" value="<?= ff_e($view['suggested_version']) ?>">
                        <div class="form-text"><?= ff_e(M::format('survey_editor.publish.version_help', ['current' => $view['current_version'] !== '' ? $view['current_version'] : '–'], 'Aktuell: {{current}}. Wird bei jeder Anmeldung mitgespeichert. Leer lassen, um sie nicht zu ändern.')) ?></div>
                    </div>
                    <div class="col-md-8">
                        <label class="form-label" for="note"><?= ff_e(M::get('survey_editor.publish.note', 'Anmerkung für den Verlauf (optional)')) ?></label>
                        <input type="text" class="form-control" id="note" name="note" maxlength="255">
                    </div>
                </div>
                <button type="submit" name="action" value="publish" class="btn btn-success"
                        onclick="return confirm('<?= ff_e(M::get('survey_editor.publish.confirm', 'Jetzt veröffentlichen? Das Formular ändert sich sofort für alle Besucher.')) ?>');"><?= ff_e(M::get('survey_editor.btn.publish', 'Veröffentlichen')) ?></button>
            </div>
        </div>
    </form>

    <div class="d-flex flex-wrap gap-4 mb-4">
        <form method="post" action="<?= ff_e($self) ?>" enctype="multipart/form-data" class="d-flex gap-2 align-items-center">
            <?php csrf_field(); ?>
            <input type="hidden" name="action" value="upload">
            <input type="file" name="survey_file" accept=".json,application/json" class="form-control form-control-sm" required>
            <button type="submit" class="btn btn-sm btn-outline-secondary text-nowrap"><?= ff_e(M::get('survey_editor.btn.upload', 'Datei laden')) ?></button>
        </form>
        <?php if ($view['draft'] !== null): ?>
            <form method="post" action="<?= ff_e($self) ?>" onsubmit="return confirm('<?= ff_e(M::get('survey_editor.discard_confirm', 'Den Entwurf wirklich verwerfen?')) ?>');">
                <?php csrf_field(); ?>
                <input type="hidden" name="action" value="discard">
                <button type="submit" class="btn btn-sm btn-outline-danger"><?= ff_e(M::get('survey_editor.btn.discard', 'Entwurf verwerfen')) ?></button>
            </form>
        <?php endif; ?>
    </div>

    <!-- Help -------------------------------------------------------------------------------------------------->
    <div class="card mb-4">
        <div class="card-header"><h5 class="mb-0"><?= ff_e(M::get('survey_editor.howto.title', 'So kommt eine Survey hierher')) ?></h5></div>
        <div class="card-body">
            <ol class="mb-2">
                <li><?= ff_e(M::get('survey_editor.howto.1', 'Im SurveyJS-Creator (kostenlose Online-Version) das Formular bearbeiten.')) ?>
                    <a href="https://surveyjs.io/create-free-survey" target="_blank" rel="noopener noreferrer">surveyjs.io/create-free-survey</a></li>
                <li><?= ff_e(M::get('survey_editor.howto.2', 'Dort den Reiter „JSON Editor" öffnen, den gesamten Text kopieren.')) ?></li>
                <li><?= ff_e(M::get('survey_editor.howto.3', 'Hier im Editor alles ersetzen, auf „Prüfen" klicken, Hinweise lesen, dann „Entwurf speichern" und „Veröffentlichen".')) ?></li>
                <li><?= ff_e(M::get('survey_editor.howto.4', 'Für eine Änderung am bestehenden Formular: den Text aus dem Editor hier („JSON kopieren") im Creator unter „JSON Editor" einfügen.')) ?></li>
            </ol>
            <p class="small text-muted mb-0"><?= ff_e(M::get('survey_editor.howto.license', 'Der Creator gehört nicht zu Ondisos; die Online-Version von surveyjs.io wird von SurveyJS betrieben.')) ?></p>
        </div>
    </div>

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
                            <form method="post" action="<?= ff_e($self) ?>" class="d-inline"
                                  onsubmit="return confirm('<?= ff_e(M::get('forms.history.confirm', 'Diesen früheren Stand wiederherstellen? Der aktuelle Stand bleibt im Verlauf erhalten.')) ?>');">
                                <?php csrf_field(); ?>
                                <input type="hidden" name="action" value="restore">
                                <input type="hidden" name="revision" value="<?= (int)$rev['id'] ?>">
                                <button type="submit" class="btn btn-sm btn-outline-secondary"><?= ff_e(M::get('forms.history.restore', 'Wiederherstellen')) ?></button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>
</div>

<script src="assets/survey-editor.js"></script>
<?php require __DIR__ . '/../inc/footer.php'; ?>
