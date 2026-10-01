<?php
// public/forms.php
// Form editor: list of the forms of the current tenant, and creating a new one.

declare(strict_types=1);

require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/auth.php';
require_once __DIR__ . '/../inc/csrf.php';
require_once __DIR__ . '/../inc/form_editor.php';
require_once __DIR__ . '/../inc/form_fields.php';

use App\Services\MessageService as M;

$createErrors = [];
$createKey    = '';

if (!$editorNoTenant && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create') {
    try {
        csrf_validate();
    } catch (\InvalidArgumentException $e) {
        editor_flash('danger', M::get('forms.csrf_failed', 'Die Sitzung ist abgelaufen. Bitte die Seite neu laden und erneut versuchen.'));
        header('Location: forms.php');
        exit;
    }

    $outcome   = $editor->create($_POST);
    $createKey = $outcome['key'];

    if ($outcome['status'] === 'created') {
        editor_flash('success', M::format('forms.created', ['form' => $outcome['key']], 'Formular „{{form}}" wurde angelegt.'));
        header('Location: form_edit.php?form=' . urlencode($outcome['key']));
        exit;
    }
    $createErrors = ff_group_by_path($outcome['result']->errors());
}

$forms = $editorNoTenant ? [] : $editor->listForms();

require __DIR__ . '/../inc/header.php';
?>

<div class="container mt-4">
    <h1 class="mb-4"><?= ff_e(M::get('forms.title', 'Formulare')) ?></h1>

    <?php foreach (editor_take_flash() as [$type, $text]): ?>
        <div class="alert alert-<?= ff_e($type) ?> alert-dismissible fade show"><?= ff_e($text) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
    <?php endforeach; ?>

<?php if ($editorNoTenant): ?>
    <div class="alert alert-info"><?= ff_e(M::get('forms.choose_tenant', 'Bitte oben rechts einen Tenant wählen: Formulare gehören immer zu genau einer Schule.')) ?></div>
<?php else: ?>

    <?php if ($forms === []): ?>
        <div class="alert alert-secondary"><?= ff_e(M::get('forms.none_yet', 'Es gibt noch kein Formular.')) ?></div>
    <?php else: ?>
        <div class="table-responsive">
            <table class="table table-hover align-middle">
                <thead>
                    <tr>
                        <th><?= ff_e(M::get('forms.col.key', 'Schlüssel')) ?></th>
                        <th><?= ff_e(M::get('forms.col.version', 'Version')) ?></th>
                        <th><?= ff_e(M::get('forms.col.survey', 'Survey')) ?></th>
                        <th class="text-end"><?= ff_e(M::get('forms.col.submissions', 'Anmeldungen')) ?></th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($forms as $f): ?>
                    <tr>
                        <td><a href="form_edit.php?form=<?= urlencode($f['key']) ?>"><code><?= ff_e($f['key']) ?></code></a></td>
                        <td><?= ff_e($f['version']) ?></td>
                        <td>
                            <?php if ($f['survey_source'] === 'database'): ?>
                                <span class="badge text-bg-success"><?= ff_e(M::get('forms.source.database', 'Backend')) ?></span>
                            <?php else: ?>
                                <span class="badge text-bg-secondary" title="<?= ff_e(M::get('forms.source.file_hint', 'Die Survey kommt aus einer Datei im Frontend.')) ?>"><?= ff_e(M::get('forms.source.file', 'Datei im Frontend')) ?></span>
                            <?php endif; ?>
                            <?php if ($f['has_draft']): ?>
                                <span class="badge text-bg-warning"><?= ff_e(M::get('forms.draft', 'Entwurf')) ?></span>
                            <?php endif; ?>
                        </td>
                        <td class="text-end"><?= (int)$f['submissions'] ?></td>
                        <td class="text-end"><a class="btn btn-sm btn-outline-primary" href="form_edit.php?form=<?= urlencode($f['key']) ?>"><?= ff_e(M::get('forms.edit', 'Bearbeiten')) ?></a></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>

    <div class="card mt-4">
        <div class="card-header"><h5 class="mb-0"><?= ff_e(M::get('forms.new.title', 'Neues Formular anlegen')) ?></h5></div>
        <div class="card-body">
            <form method="post" action="forms.php" class="row g-3 align-items-start">
                <?php csrf_field(); ?>
                <input type="hidden" name="action" value="create">
                <div class="col-md-6">
                    <label class="form-label" for="form_key"><?= ff_e(M::get('forms.new.key', 'Schlüssel')) ?></label>
                    <input type="text" id="form_key" name="form_key" class="form-control<?= isset($createErrors['form_key']) ? ' is-invalid' : '' ?>"
                           value="<?= ff_e($createKey) ?>" maxlength="64" pattern="[a-z0-9][a-z0-9_\-]*" required>
                    <div class="form-text"><?= ff_e(M::get('forms.new.key_help', 'Kleinbuchstaben, Ziffern, _ und -. Der Schlüssel steht in der Adresse (?form=…) und im WordPress-Shortcode [ondisos form="…"] und lässt sich später nicht mehr ändern.')) ?></div>
                    <?php foreach ($createErrors['form_key'] ?? [] as $m): ?><div class="invalid-feedback d-block"><?= ff_e($m) ?></div><?php endforeach; ?>
                    <?php foreach ($createErrors as $path => $messages): if ($path === 'form_key') { continue; } foreach ($messages as $m): ?>
                        <div class="invalid-feedback d-block"><?= ff_e($path . ': ' . $m) ?></div>
                    <?php endforeach; endforeach; ?>
                </div>
                <div class="col-md-6 pt-md-4">
                    <button type="submit" class="btn btn-primary"><?= ff_e(M::get('forms.new.submit', 'Anlegen')) ?></button>
                </div>
            </form>
        </div>
    </div>
<?php endif; ?>
</div>

<?php require __DIR__ . '/../inc/footer.php'; ?>
