<?php
// public/form_edit.php
// Form editor: the configuration of one form as an HTML form (no JSON for school admins).

declare(strict_types=1);

require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/auth.php';
require_once __DIR__ . '/../inc/csrf.php';
require_once __DIR__ . '/../inc/form_editor.php';
require_once __DIR__ . '/../inc/form_fields.php';

use App\Forms\FormConfigFormMapper;
use App\Forms\FormConfigSchema;
use App\Services\MessageService as M;

$formKey = is_string($_GET['form'] ?? null) ? $_GET['form'] : '';

if ($editorNoTenant) {
    editor_flash('info', M::get('forms.choose_tenant', 'Bitte oben rechts einen Tenant wählen: Formulare gehören immer zu genau einer Schule.'));
    header('Location: forms.php');
    exit;
}

$errors   = [];   // path => messages (blocking)
$warnings = [];   // path => messages
$conflict = false;
$posted   = null; // submitted values after a failed save, shown again

// ---------------------------------------------------------------------------
// POST actions (PRG: success redirects)
// ---------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        csrf_validate();
    } catch (\InvalidArgumentException $e) {
        editor_flash('danger', M::get('forms.csrf_failed', 'Die Sitzung ist abgelaufen. Bitte die Seite neu laden und erneut versuchen.'));
        header('Location: form_edit.php?form=' . urlencode($formKey));
        exit;
    }

    $action = (string)($_POST['action'] ?? '');

    if ($action === 'save') {
        $outcome = $editor->save($formKey, $_POST);
        switch ($outcome['status']) {
            case 'saved':
                editor_flash('success', M::get('forms.saved', 'Gespeichert.'));
                foreach ($outcome['result']->warnings() as $w) {
                    editor_flash('warning', ($w['path'] !== '' ? $w['path'] . ': ' : '') . $w['message']);
                }
                header('Location: form_edit.php?form=' . urlencode($formKey));
                exit;
            case 'not_found':
                http_response_code(404);
                break;
            case 'conflict':
                $conflict = true;
                break;
            default: // invalid
                $errors   = ff_group_by_path($outcome['result']->errors());
                $warnings = ff_group_by_path($outcome['result']->warnings());
                $posted   = $outcome['submitted'];
        }
    } elseif ($action === 'delete') {
        $outcome = $editor->delete($formKey);
        if ($outcome['status'] === 'deleted') {
            editor_flash('success', M::format('forms.deleted', ['form' => $formKey], 'Formular „{{form}}" wurde gelöscht.'));
            header('Location: forms.php');
            exit;
        }
        foreach ($outcome['result']->errors() as $e) {
            editor_flash('danger', $e['message']);
        }
        header('Location: form_edit.php?form=' . urlencode($formKey));
        exit;
    } elseif ($action === 'restore') {
        $outcome = $editor->restore($formKey, (int)($_POST['revision'] ?? 0));
        if ($outcome['status'] === 'restored') {
            editor_flash('success', M::get('forms.restored', 'Der frühere Stand wurde wiederhergestellt.'));
        } else {
            foreach ($outcome['result']->errors() as $e) {
                editor_flash('danger', $e['message']);
            }
            if ($outcome['status'] === 'not_found') {
                editor_flash('danger', M::get('forms.revision_not_found', 'Diesen Stand gibt es nicht.'));
            }
        }
        header('Location: form_edit.php?form=' . urlencode($formKey));
        exit;
    }
}

$form = $editor->load($formKey);
if ($form === null) {
    http_response_code(404);
    require __DIR__ . '/../inc/header.php';
    echo '<div class="container mt-4"><div class="alert alert-danger">' . ff_e(M::get('forms.not_found', 'Dieses Formular gibt es nicht.')) . '</div>'
       . '<a href="forms.php" class="btn btn-secondary">' . ff_e(M::get('forms.back', '← Zurück zu den Formularen')) . '</a></div>';
    require __DIR__ . '/../inc/footer.php';
    exit;
}

// After a failed save show what the admin typed, not the stored values.
$values = $posted !== null
    ? FormConfigFormMapper::formValues(FormConfigFormMapper::overlay($form['config'], $posted))
    : $form['values'];

$groups = ['general', 'email', 'pdf', 'ical'];

require __DIR__ . '/../inc/header.php';
?>

<div class="container mt-4 mb-5">
    <nav aria-label="breadcrumb" class="mb-3">
        <ol class="breadcrumb">
            <li class="breadcrumb-item"><a href="forms.php"><?= ff_e(M::get('forms.title', 'Formulare')) ?></a></li>
            <li class="breadcrumb-item active"><code><?= ff_e($formKey) ?></code></li>
        </ol>
    </nav>

    <h1 class="mb-3"><?= ff_e(M::format('forms.edit_title', ['form' => $formKey], 'Formular „{{form}}"')) ?></h1>

    <?php foreach (editor_take_flash() as [$type, $text]): ?>
        <div class="alert alert-<?= ff_e($type) ?> alert-dismissible fade show"><?= ff_e($text) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
    <?php endforeach; ?>

    <?php if ($conflict): ?>
        <div class="alert alert-danger">
            <?= ff_e(M::get('forms.conflict', 'Das Formular wurde inzwischen von jemand anderem geändert. Ihre Änderungen wurden nicht gespeichert.')) ?>
            <a href="form_edit.php?form=<?= urlencode($formKey) ?>" class="alert-link"><?= ff_e(M::get('forms.reload', 'Aktuellen Stand laden')) ?></a>
        </div>
    <?php endif; ?>

    <?php if ($errors !== []): ?>
        <div class="alert alert-danger"><?= ff_e(M::get('forms.fix_errors', 'Bitte die markierten Felder korrigieren. Es wurde nichts gespeichert.')) ?></div>
        <?php if (isset($errors[''])): foreach ($errors[''] as $m): ?><div class="alert alert-danger"><?= ff_e($m) ?></div><?php endforeach; endif; ?>
    <?php endif; ?>

    <?php if ($posted !== null): ?>
        <?php foreach ($warnings as $path => $messages): foreach ($messages as $m): ?>
            <div class="alert alert-warning"><?= ff_e(($path !== '' ? $path . ': ' : '') . $m) ?></div>
        <?php endforeach; endforeach; ?>
    <?php endif; ?>

    <form method="post" action="form_edit.php?form=<?= urlencode($formKey) ?>" id="config-form">
        <?php csrf_field(); ?>
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="sha256" value="<?= ff_e($form['sha256']) ?>">

        <?php foreach ($groups as $group): $fields = ff_group_fields($group); if ($fields === []) { continue; } ?>
            <div class="card mb-4">
                <div class="card-header"><h5 class="mb-0"><?= ff_e(M::get('forms.groups.' . $group, $group)) ?></h5></div>
                <div class="card-body">
                    <?php foreach ($fields as $path => $field):
                        echo ff_render($path, $field, $values[$path] ?? null, $errors, $editor->canEdit($path), $form['survey_fields']);
                    endforeach; ?>
                </div>
            </div>
        <?php endforeach; ?>

        <div class="card mb-4">
            <div class="card-header"><h5 class="mb-0"><?= ff_e(M::get('forms.groups.files', 'Dateien')) ?></h5></div>
            <div class="card-body">
                <?php foreach (ff_group_fields('files') as $path => $field):
                    echo ff_render($path, $field, $values[$path] ?? null, $errors, $editor->canEdit($path), null);
                endforeach; ?>
            </div>
        </div>

        <div class="d-flex gap-2 mb-4">
            <button type="submit" class="btn btn-primary"><?= ff_e(M::get('forms.save', 'Speichern')) ?></button>
            <a href="forms.php" class="btn btn-outline-secondary"><?= ff_e(M::get('forms.cancel', 'Abbrechen')) ?></a>
        </div>
    </form>

    <div class="card mb-4">
        <div class="card-header"><h5 class="mb-0"><?= ff_e(M::get('forms.survey.title', 'Survey (Fragen des Formulars)')) ?></h5></div>
        <div class="card-body">
            <p class="mb-1"><?= ff_e(M::get('forms.survey.file', 'Datei')) ?>: <code><?= ff_e($form['survey_name'] ?? '–') ?></code></p>
            <?php if ($form['survey_source'] === 'database'): ?>
                <p class="mb-1"><span class="badge text-bg-success"><?= ff_e(M::get('forms.source.database', 'Backend')) ?></span>
                    <?= ff_e(M::format('forms.survey.fields', ['count' => count($form['survey_fields'] ?? [])], '{{count}} Felder')) ?></p>
            <?php else: ?>
                <p class="mb-1"><span class="badge text-bg-secondary"><?= ff_e(M::get('forms.source.file', 'Datei im Frontend')) ?></span></p>
                <p class="text-muted mb-0"><?= ff_e(M::get('forms.survey.import_hint', 'Diese Survey liegt als Datei im Frontend. Mit „php import-surveys.php" lässt sie sich ins Backend übernehmen (siehe MIGRATION-3.1.md).')) ?></p>
            <?php endif; ?>
            <?php if ($form['has_draft']): ?><p class="mb-0 mt-2"><span class="badge text-bg-warning"><?= ff_e(M::get('forms.draft', 'Entwurf')) ?></span></p><?php endif; ?>
        </div>
    </div>

    <?php if ($form['revisions'] !== []): ?>
    <div class="card mb-4">
        <div class="card-header"><h5 class="mb-0"><?= ff_e(M::get('forms.history.title', 'Verlauf')) ?></h5></div>
        <div class="table-responsive">
            <table class="table table-sm align-middle mb-0">
                <thead><tr>
                    <th><?= ff_e(M::get('forms.history.when', 'Wann')) ?></th>
                    <th><?= ff_e(M::get('forms.history.what', 'Was')) ?></th>
                    <th><?= ff_e(M::get('forms.history.note', 'Anmerkung')) ?></th>
                    <th><?= ff_e(M::get('forms.history.who', 'Wer')) ?></th>
                    <th></th>
                </tr></thead>
                <tbody>
                <?php foreach ($form['revisions'] as $rev): ?>
                    <tr>
                        <td class="text-nowrap"><?= ff_e($rev['created_at']) ?></td>
                        <td><?= ff_e(M::get('forms.history.kind.' . $rev['kind'], $rev['kind'])) ?></td>
                        <td><?= ff_e($rev['note'] ?? '') ?></td>
                        <td><?= ff_e($rev['created_by'] ?? '') ?></td>
                        <td class="text-end">
                            <?php if (in_array($rev['kind'], ['config', 'survey'], true)): ?>
                                <form method="post" action="form_edit.php?form=<?= urlencode($formKey) ?>" class="d-inline"
                                      onsubmit="return confirm('<?= ff_e(M::get('forms.history.confirm', 'Diesen früheren Stand wiederherstellen? Der aktuelle Stand bleibt im Verlauf erhalten.')) ?>');">
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

    <div class="card border-danger mb-4">
        <div class="card-header text-danger"><h5 class="mb-0"><?= ff_e(M::get('forms.delete.title', 'Formular löschen')) ?></h5></div>
        <div class="card-body">
            <?php if ($form['can_delete']): ?>
                <p><?= ff_e(M::get('forms.delete.help', 'Das Formular ist dann nicht mehr erreichbar. Der Verlauf bleibt erhalten.')) ?></p>
                <form method="post" action="form_edit.php?form=<?= urlencode($formKey) ?>"
                      onsubmit="return confirm('<?= ff_e(M::get('forms.delete.confirm', 'Dieses Formular wirklich löschen?')) ?>');">
                    <?php csrf_field(); ?>
                    <input type="hidden" name="action" value="delete">
                    <button type="submit" class="btn btn-outline-danger"><?= ff_e(M::get('forms.delete.button', 'Löschen')) ?></button>
                </form>
            <?php else: ?>
                <p class="mb-0 text-muted"><?= ff_e(M::format('forms.delete.refused', ['count' => $form['submissions']], 'Zu diesem Formular gibt es {{count}} Anmeldung(en); es kann nicht gelöscht werden.')) ?></p>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($form['role'] === FormConfigSchema::ROLE_PLATFORM): ?>
    <details class="mb-4">
        <summary class="text-muted"><?= ff_e(M::get('forms.diagnose', 'Diagnose: gespeicherte Konfiguration (JSON, nur lesbar)')) ?></summary>
        <pre class="bg-light border rounded p-3 mt-2 small user-select-all"><?= ff_e(json_encode($form['config'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?></pre>
    </details>
    <?php endif; ?>
</div>

<?php require __DIR__ . '/../inc/footer.php'; ?>
