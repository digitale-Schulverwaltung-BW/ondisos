<?php
// public/themes.php
// Theme editor (3.2.0b): the themes of the current tenant, with the forms that use each.

declare(strict_types=1);

require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/auth.php';
require_once __DIR__ . '/../inc/form_editor.php';
require_once __DIR__ . '/../inc/form_fields.php';

use App\Services\MessageService as M;

if ($editorNoTenant) {
    editor_flash('info', M::get('forms.choose_tenant', 'Bitte oben rechts einen Tenant wählen: Formulare gehören immer zu genau einer Schule.'));
    header('Location: forms.php');
    exit;
}

$themes = $themeEditor->listThemes();

require __DIR__ . '/../inc/header.php';
?>

<div class="container mt-4 mb-5">
    <nav aria-label="breadcrumb" class="mb-3">
        <ol class="breadcrumb">
            <li class="breadcrumb-item"><a href="forms.php"><?= ff_e(M::get('forms.title', 'Formulare')) ?></a></li>
            <li class="breadcrumb-item active"><?= ff_e(M::get('theme_editor.list_title', 'Design (Themes)')) ?></li>
        </ol>
    </nav>

    <h1 class="mb-3"><?= ff_e(M::get('theme_editor.list_title', 'Design (Themes)')) ?></h1>

    <?php foreach (editor_take_flash() as [$type, $text]): ?>
        <div class="alert alert-<?= ff_e($type) ?> alert-dismissible fade show"><?= ff_e($text) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
    <?php endforeach; ?>

    <p class="text-muted"><?= ff_e(M::get('theme_editor.list_help', 'Ein Theme legt Farben, Schrift und Kartenstil der Formulare fest. Es gilt für alle Formulare, die es nutzen; welches Theme ein Formular nutzt, steht in dessen Konfiguration.')) ?></p>

    <?php if ($themes === []): ?>
        <div class="alert alert-secondary"><?= ff_e(M::get('theme_editor.none', 'Es gibt noch kein Theme. Ein Theme erscheint hier, sobald ein Formular es in seiner Konfiguration nennt.')) ?></div>
    <?php else: ?>
        <div class="table-responsive">
            <table class="table table-hover align-middle">
                <thead><tr>
                    <th><?= ff_e(M::get('theme_editor.col.name', 'Theme')) ?></th>
                    <th><?= ff_e(M::get('theme_editor.col.status', 'Stand')) ?></th>
                    <th><?= ff_e(M::get('theme_editor.col.forms', 'Genutzt von')) ?></th>
                    <th></th>
                </tr></thead>
                <tbody>
                <?php foreach ($themes as $t): ?>
                    <tr>
                        <td><a href="theme_edit.php?name=<?= urlencode($t['name']) ?>"><code><?= ff_e($t['name']) ?></code></a></td>
                        <td>
                            <?php if ($t['exists']): ?>
                                <span class="badge text-bg-success"><?= ff_e(M::get('theme_editor.status.stored', 'Im Backend')) ?></span>
                                <?php if ($t['updated_at']): ?><small class="text-muted"><?= ff_e($t['updated_at']) ?></small><?php endif; ?>
                            <?php else: ?>
                                <span class="badge text-bg-secondary"><?= ff_e(M::get('theme_editor.status.missing', 'Noch nicht angelegt')) ?></span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($t['forms'] === []): ?>
                                <span class="text-muted"><?= ff_e(M::get('theme_editor.unused', 'kein Formular')) ?></span>
                            <?php else: ?>
                                <?php foreach ($t['forms'] as $i => $f): ?><?= $i > 0 ? ', ' : '' ?><a href="form_edit.php?form=<?= urlencode($f) ?>"><code><?= ff_e($f) ?></code></a><?php endforeach; ?>
                            <?php endif; ?>
                        </td>
                        <td class="text-end"><a class="btn btn-sm btn-outline-primary" href="theme_edit.php?name=<?= urlencode($t['name']) ?>"><?= ff_e(M::get('forms.edit', 'Bearbeiten')) ?></a></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php if (in_array(false, array_column($themes, 'exists'), true)): ?>
        <p class="small text-muted"><?= ff_e(M::get('theme_editor.missing_help', '„Noch nicht angelegt“: Ein Formular nennt dieses Theme, im Backend liegt aber keines. Das Formular nutzt dann die Datei im Frontend, falls vorhanden. Beim Bearbeiten startet das Theme mit der eingebauten Vorgabe.')) ?></p>
        <?php endif; ?>
    <?php endif; ?>
</div>

<?php require __DIR__ . '/../inc/footer.php'; ?>
