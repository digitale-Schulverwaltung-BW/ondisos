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
use App\Services\AuditLogger;
use App\Services\FormPdfAttachmentService;
use App\Services\MessageService as M;

$formKey = is_string($_GET['form'] ?? null) ? $_GET['form'] : '';
$attachments = new FormPdfAttachmentService();

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
    } elseif ($action === 'attachment_upload' || $action === 'attachment_delete') {
        // The PDF a school attaches to the confirmation of this form (only for forms that exist in the current tenant).
        $tenantId = \App\Config\TenantContext::getTenantId();
        if ($editor->load($formKey) === null) {
            http_response_code(404);
        } else {
            if ($action === 'attachment_upload') {
                $problem = $attachments->saveUpload($tenantId, $formKey, is_array($_FILES['attachment'] ?? null) ? $_FILES['attachment'] : []);
                if ($problem === null) {
                    AuditLogger::formEvent('form_pdf_attachment_saved', $formKey, ['user' => (string)($_SESSION['admin_username'] ?? '')]);
                    editor_flash('success', M::get('forms.attachment.saved', 'Das PDF wurde gespeichert. Es wird ab sofort an die PDF-Bestätigung angehängt.'));
                } else {
                    editor_flash('danger', $problem);
                }
            } else {
                $attachments->delete($tenantId, $formKey);
                AuditLogger::formEvent('form_pdf_attachment_deleted', $formKey, ['user' => (string)($_SESSION['admin_username'] ?? '')]);
                editor_flash('success', M::get('forms.attachment.removed', 'Das angehängte PDF wurde entfernt.'));
            }
            header('Location: form_edit.php?form=' . urlencode($formKey) . '&tab=pdf');
            exit;
        }
    } elseif ($action === 'delete') {
        $outcome = $editor->delete($formKey);
        if ($outcome['status'] === 'deleted') {
            $attachments->delete(\App\Config\TenantContext::getTenantId(), $formKey);
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
$logoInfo = (new \App\Services\TenantLogoService())->info(\App\Config\TenantContext::getTenantId());
$attachmentInfo = $attachments->info(\App\Config\TenantContext::getTenantId(), $formKey);

$values = $posted !== null
    ? FormConfigFormMapper::formValues(FormConfigFormMapper::overlay($form['config'], $posted))
    : $form['values'];

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

    <?php
    // One tab per config group ("Allgemein" also carries the system files); a tab with validation errors is flagged and opened first.
    $tabs = ['general' => ['general', 'files'], 'email' => ['email'], 'pdf' => ['pdf'], 'ical' => ['ical']];
    $tabErrors = [];
    foreach ($tabs as $tab => $tabGroups) {
        $tabErrors[$tab] = 0;
        foreach ($tabGroups as $g) {
            foreach (array_keys(ff_group_fields($g)) as $path) {
                $tabErrors[$tab] += count($errors[$path] ?? []);
            }
        }
    }
    $activeTab = isset($_GET['tab']) && is_string($_GET['tab']) && isset($tabs[$_GET['tab']]) ? $_GET['tab'] : 'general';
    foreach ($tabErrors as $tab => $n) {
        if ($n > 0) { $activeTab = $tab; break; }
    }
    $tabNames = [...array_keys($tabs), 'info'];
    ?>
    <div class="d-flex flex-wrap justify-content-between align-items-end gap-2 border-bottom mb-3">
        <ul class="nav nav-tabs border-0" id="form-tabs" role="tablist">
            <?php foreach ($tabNames as $tab): ?>
                <li class="nav-item" role="presentation">
                    <button type="button" class="nav-link<?= $tab === $activeTab ? ' active' : '' ?>" id="tab-btn-<?= ff_e($tab) ?>" data-bs-toggle="tab"
                            data-bs-target="#tab-<?= ff_e($tab) ?>" role="tab" aria-controls="tab-<?= ff_e($tab) ?>" aria-selected="<?= $tab === $activeTab ? 'true' : 'false' ?>">
                        <?= ff_e(M::get('forms.groups.' . $tab, $tab)) ?>
                        <?php if (($tabErrors[$tab] ?? 0) > 0): ?><span class="badge text-bg-danger ms-1"><?= (int)$tabErrors[$tab] ?></span><?php endif; ?>
                    </button>
                </li>
            <?php endforeach; ?>
        </ul>
        <div class="d-flex gap-2 pb-2">
            <button type="submit" form="config-form" class="btn btn-primary"><?= ff_e(M::get('forms.save', 'Speichern')) ?></button>
            <a href="forms.php" class="btn btn-outline-secondary"><?= ff_e(M::get('forms.cancel', 'Abbrechen')) ?></a>
        </div>
    </div>

    <?php // The config form is itself a .tab-content, so the Info tab (with forms of its own) can sit outside of it. ?>
    <form method="post" action="form_edit.php?form=<?= urlencode($formKey) ?>" id="config-form" class="tab-content">
        <?php csrf_field(); ?>
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="sha256" value="<?= ff_e($form['sha256']) ?>">

        <?php foreach ($tabs as $tab => $tabGroups): ?>
            <div class="tab-pane fade<?= $tab === $activeTab ? ' show active' : '' ?>" id="tab-<?= ff_e($tab) ?>" role="tabpanel" aria-labelledby="tab-btn-<?= ff_e($tab) ?>" tabindex="0">
                <?php if ($tab === 'pdf'): ?>
                    <div class="card mb-4">
                        <div class="card-body d-flex flex-wrap align-items-center gap-3">
                            <?php if ($logoInfo !== null): ?>
                                <img src="tenant_logo.php?v=<?= (int)$logoInfo['bytes'] ?>" alt="" class="border rounded p-1 bg-white" style="max-height: 56px; max-width: 160px;">
                            <?php endif; ?>
                            <div class="flex-grow-1">
                                <strong><?= ff_e(M::get('forms.logo.in_form', 'Logo der Schule')) ?></strong><br>
                                <span class="text-muted small"><?= ff_e($logoInfo !== null ? M::get('forms.logo.in_form_help', 'Wird für alle Formulare der Schule verwendet und auf der Seite „Formulare" geändert.') : M::get('forms.logo.in_form_none', 'Noch kein Logo hochgeladen.')) ?></span>
                                <a class="small ms-1" href="forms.php#logo"><?= ff_e(M::get('forms.logo.in_form_link', 'Logo ändern')) ?></a>
                            </div>
                            <button type="submit" form="config-form" formaction="form_pdf_preview.php?form=<?= urlencode($formKey) ?>" formtarget="_blank"
                                    class="btn btn-outline-primary"><?= ff_e(M::get('forms.pdf_preview.button', 'PDF-Vorschau')) ?></button>
                        </div>
                        <div class="card-footer text-muted small"><?= ff_e(M::get('forms.pdf_preview.help', 'Zeigt die PDF-Bestätigung mit Beispielangaben (Feldname, 1.1.2000, 1) und Ihren aktuellen, noch nicht gespeicherten Eingaben.')) ?></div>
                    </div>
                <?php endif; ?>
                <?php if ($tab === 'pdf'): ?>
                    <div class="card mb-4" id="attachment">
                        <div class="card-header"><h5 class="mb-0"><?= ff_e(M::get('forms.attachment.title', 'Zusätzliches PDF anhängen')) ?></h5></div>
                        <div class="card-body">
                            <p class="text-muted"><?= ff_e(M::get('forms.attachment.help', 'Dieses PDF wird automatisch hinter die PDF-Bestätigung dieses Formulars gesetzt (zum Beispiel ein Informationsblatt). Höchstens 5 MB und 20 Seiten. Es gilt sofort auch für bereits eingegangene Anmeldungen.')) ?></p>
                            <?php // The upload forms sit outside the config form (forms cannot be nested); the controls here point to them with form="…". ?>
                            <?php if ($attachmentInfo !== null): ?>
                                <p class="mb-2"><span class="badge text-bg-success"><?= ff_e(M::get('forms.attachment.present', 'PDF hinterlegt')) ?></span>
                                    <strong><?= ff_e($attachmentInfo['name']) ?></strong>
                                    <span class="text-muted small">(<?= $attachmentInfo['pages'] > 0 ? (int)$attachmentInfo['pages'] . ' ' . ff_e(M::get('forms.attachment.pages', 'Seiten')) . ', ' : '' ?><?= ff_e(number_format($attachmentInfo['bytes'] / 1024, 0, ',', '.')) ?> KB)</span></p>
                            <?php else: ?>
                                <p class="mb-2"><span class="badge text-bg-secondary"><?= ff_e(M::get('forms.attachment.none', 'Kein PDF angehängt')) ?></span></p>
                            <?php endif; ?>
                            <div class="d-flex flex-wrap gap-2 align-items-center">
                                <input type="file" class="form-control" style="max-width: 24rem;" name="attachment" form="attachment-form" accept="application/pdf,.pdf" required
                                       aria-label="<?= ff_e(M::get('forms.attachment.file', 'PDF-Datei')) ?>">
                                <button type="submit" form="attachment-form" class="btn btn-primary"><?= ff_e($attachmentInfo !== null ? M::get('forms.attachment.replace', 'PDF ersetzen') : M::get('forms.attachment.upload', 'PDF hochladen')) ?></button>
                                <?php if ($attachmentInfo !== null): ?>
                                    <button type="submit" form="attachment-delete-form" class="btn btn-outline-danger"><?= ff_e(M::get('forms.attachment.remove', 'Entfernen')) ?></button>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="card-footer text-muted small"><?= ff_e(M::get('forms.attachment.preview_note', 'Die PDF-Vorschau zeigt das angehängte PDF ebenfalls.')) ?></div>
                    </div>
                <?php endif; ?>
                <?php foreach ($tabGroups as $group): $fields = ff_group_fields($group); if ($fields === []) { continue; } ?>
                    <div class="card mb-4">
                        <?php if (count($tabGroups) > 1): ?>
                            <div class="card-header"><h5 class="mb-0"><?= ff_e(M::get('forms.groups.' . $group, $group)) ?></h5></div>
                        <?php endif; ?>
                        <div class="card-body">
                            <?php foreach ($fields as $path => $field):
                                // The logo of a school is uploaded on the form list; the file-path field is for platform admins only.
                                if ($path === 'pdf.logo' && $form['role'] !== FormConfigSchema::ROLE_PLATFORM) { continue; }
                                echo ff_render($path, $field, $values[$path] ?? null, $errors, $editor->canEdit($path), $group === 'files' ? null : $form['survey_fields']);
                            endforeach; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endforeach; ?>
    </form>

    <form method="post" action="form_edit.php?form=<?= urlencode($formKey) ?>" enctype="multipart/form-data" id="attachment-form">
        <?php csrf_field(); ?>
        <input type="hidden" name="action" value="attachment_upload">
    </form>
    <form method="post" action="form_edit.php?form=<?= urlencode($formKey) ?>" id="attachment-delete-form"
          onsubmit="return confirm('<?= ff_e(M::get('forms.attachment.confirm_remove', 'Das angehängte PDF wirklich entfernen?')) ?>');">
        <?php csrf_field(); ?>
        <input type="hidden" name="action" value="attachment_delete">
    </form>

    <div class="tab-content">
    <div class="tab-pane fade" id="tab-info" role="tabpanel" aria-labelledby="tab-btn-info" tabindex="0">

    <div class="card mb-4">
        <div class="card-header"><h5 class="mb-0"><?= ff_e(M::get('forms.survey.title', 'Survey (Fragen des Formulars)')) ?></h5></div>
        <div class="card-body">
            <p class="mb-1"><?= ff_e(M::get('forms.survey.file', 'Datei')) ?>: <code><?= ff_e($form['survey_name'] ?? '–') ?></code></p>
            <?php if ($form['survey_source'] === 'database'): ?>
                <p class="mb-1"><span class="badge text-bg-success"><?= ff_e(M::get('forms.source.database', 'Backend')) ?></span>
                    <?= ff_e(M::format('forms.survey.fields', ['count' => count($form['survey_fields'] ?? [])], '{{count}} Felder')) ?></p>
            <?php else: ?>
                <p class="mb-1"><span class="badge text-bg-secondary"><?= ff_e(M::get('forms.source.file', 'Datei im Frontend')) ?></span></p>
                <p class="text-muted mb-0"><?= ff_e(M::get('forms.survey.import_hint', 'Diese Survey liegt als Datei im Frontend. Mit „php import-surveys.php" lässt sie sich ins Backend übernehmen (siehe docs/betreiber/MIGRATION-3.1.md).')) ?></p>
            <?php endif; ?>
            <?php if ($form['has_draft']): ?><p class="mb-0 mt-2"><span class="badge text-bg-warning"><?= ff_e(M::get('forms.draft', 'Entwurf')) ?></span></p><?php endif; ?>
            <a href="form_survey.php?form=<?= urlencode($formKey) ?>" class="btn btn-outline-primary mt-3"><?= ff_e(M::get('forms.survey.edit', 'Survey bearbeiten')) ?></a>
            <?php if ($form['survey_source'] === 'database' || $form['has_draft']): ?>
                <a href="form_preview.php?form=<?= urlencode($formKey) ?>&source=<?= $form['has_draft'] ? 'draft' : 'live' ?>" class="btn btn-outline-secondary mt-3"><?= ff_e(M::get('survey_editor.btn.preview', 'Vorschau')) ?></a>
            <?php endif; ?>
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
    </div>
</div>

<?php require __DIR__ . '/../inc/footer.php'; ?>
