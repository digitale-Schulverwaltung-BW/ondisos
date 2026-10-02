<?php
// public/forms.php
// Form editor: list of the forms of the current tenant, and creating a new one.

declare(strict_types=1);

require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/auth.php';
require_once __DIR__ . '/../inc/csrf.php';
require_once __DIR__ . '/../inc/form_editor.php';
require_once __DIR__ . '/../inc/form_fields.php';
require_once __DIR__ . '/../inc/form_copy.php';

use App\Services\AuditLogger;
use App\Services\MessageService as M;
use App\Services\TenantAccentColor;
use App\Services\TenantLogoService;

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

// Platform admins can copy the forms of another tenant into the current one.
if (!$editorNoTenant && $editorRole === \App\Forms\FormConfigSchema::ROLE_PLATFORM && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'copy') {
    try {
        csrf_validate();
        form_copy_run((int)($_POST['copy_from'] ?? 0), \App\Config\TenantContext::getTenantId(), !empty($_POST['overwrite']));
    } catch (\InvalidArgumentException $e) {
        editor_flash('danger', M::get('forms.csrf_failed', 'Die Sitzung ist abgelaufen. Bitte die Seite neu laden und erneut versuchen.'));
    }
    header('Location: forms.php');
    exit;
}

// PDF logo of the school: upload or remove (tenant admins and platform admins alike).
$tenantLogos = new TenantLogoService();
if (!$editorNoTenant && $_SERVER['REQUEST_METHOD'] === 'POST' && in_array($_POST['action'] ?? '', ['logo_upload', 'logo_delete'], true)) {
    try {
        csrf_validate();
        $logoTenant = \App\Config\TenantContext::getTenantId();
        if ($_POST['action'] === 'logo_upload') {
            $problem = $tenantLogos->saveUpload($logoTenant, is_array($_FILES['logo'] ?? null) ? $_FILES['logo'] : []);
            if ($problem === null) {
                AuditLogger::formEvent('tenant_logo_saved', '', ['user' => (string)($_SESSION['admin_username'] ?? '')]);
                editor_flash('success', M::get('forms.logo.saved', 'Das Logo wurde gespeichert. Es erscheint ab sofort in den PDF-Bestätigungen.'));
            } else {
                editor_flash('danger', $problem);
            }
        } else {
            $tenantLogos->delete($logoTenant);
            AuditLogger::formEvent('tenant_logo_deleted', '', ['user' => (string)($_SESSION['admin_username'] ?? '')]);
            editor_flash('success', M::get('forms.logo.removed', 'Das Logo wurde entfernt.'));
        }
    } catch (\InvalidArgumentException $e) {
        editor_flash('danger', M::get('forms.csrf_failed', 'Die Sitzung ist abgelaufen. Bitte die Seite neu laden und erneut versuchen.'));
    }
    header('Location: forms.php#logo');
    exit;
}

// Accent colour of the school's PDFs: save or reset to the default (tenant admins and platform admins alike).
$tenantAccent = new TenantAccentColor();
if (!$editorNoTenant && $_SERVER['REQUEST_METHOD'] === 'POST' && in_array($_POST['action'] ?? '', ['accent_save', 'accent_reset'], true)) {
    try {
        csrf_validate();
        $accentTenant = \App\Config\TenantContext::getTenantId();
        if ($_POST['action'] === 'accent_save') {
            $problem = $tenantAccent->save($accentTenant, (string)($_POST['accent_color'] ?? ''));
            if ($problem === null) {
                AuditLogger::formEvent('tenant_accent_saved', '', ['user' => (string)($_SESSION['admin_username'] ?? '')]);
                editor_flash('success', M::get('forms.accent.saved', 'Die Farbe wurde gespeichert. Sie gilt ab sofort in den PDF-Bestätigungen.'));
            } else {
                editor_flash('danger', $problem);
            }
        } else {
            $tenantAccent->delete($accentTenant);
            AuditLogger::formEvent('tenant_accent_reset', '', ['user' => (string)($_SESSION['admin_username'] ?? '')]);
            editor_flash('success', M::get('forms.accent.reset_done', 'Die Farbe wurde zurückgesetzt.'));
        }
    } catch (\InvalidArgumentException $e) {
        editor_flash('danger', M::get('forms.csrf_failed', 'Die Sitzung ist abgelaufen. Bitte die Seite neu laden und erneut versuchen.'));
    }
    header('Location: forms.php#farbe');
    exit;
}

$forms = $editorNoTenant ? [] : $editor->listForms();
$copySources = [];
if (!$editorNoTenant && $editorRole === \App\Forms\FormConfigSchema::ROLE_PLATFORM) {
    $currentId   = \App\Config\TenantContext::getTenantId();
    $copySources = array_values(array_filter((new \App\Repositories\TenantRepository())->findAll(), static fn (array $t): bool => (int)$t['id'] !== $currentId));
}
[$copyReport, $copyFlash] = form_copy_take();
$logoInfo = $editorNoTenant ? null : $tenantLogos->info(\App\Config\TenantContext::getTenantId());
$accentSaved = $editorNoTenant ? null : $tenantAccent->get(\App\Config\TenantContext::getTenantId());
$accentShown = $accentSaved ?? TenantAccentColor::DEFAULT;

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

    <?php if ($copyFlash !== null): ?><div class="alert alert-<?= ff_e($copyFlash[0]) ?>"><?= ff_e($copyFlash[1]) ?></div><?php endif; ?>
    <?php if ($copyReport !== null): echo form_copy_report_html($copyReport); endif; ?>

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
                        <td class="text-end"><a class="btn btn-sm btn-outline-primary" href="form_edit.php?form=<?= urlencode($f['key']) ?>"><?= ff_e(M::get('forms.edit', 'Bearbeiten')) ?></a>
                            <a class="btn btn-sm btn-outline-secondary" href="form_survey.php?form=<?= urlencode($f['key']) ?>"><?= ff_e(M::get('forms.survey.edit', 'Survey bearbeiten')) ?></a></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>


    <div class="card mt-4" id="logo">
        <div class="card-header"><h5 class="mb-0"><?= ff_e(M::get('forms.logo.title', 'Logo der Schule')) ?></h5></div>
        <div class="card-body">
            <p class="text-muted"><?= ff_e(M::get('forms.logo.help', 'Das Logo erscheint oben in den PDF-Bestätigungen aller Formulare dieser Schule. PNG oder JPEG, höchstens 2 MB; ein transparenter Hintergrund bleibt bei PNG erhalten.')) ?></p>
            <div class="row g-3 align-items-center">
                <?php if ($logoInfo !== null): ?>
                    <div class="col-auto">
                        <img src="tenant_logo.php?v=<?= (int)$logoInfo['bytes'] ?>" alt="<?= ff_e(M::get('forms.logo.title', 'Logo der Schule')) ?>"
                             class="border rounded p-2 bg-white" style="max-width: 220px; max-height: 120px;">
                    </div>
                <?php else: ?>
                    <div class="col-12"><span class="badge text-bg-secondary"><?= ff_e(M::get('forms.logo.none', 'Noch kein Logo hochgeladen')) ?></span></div>
                <?php endif; ?>
                <div class="col">
                    <form method="post" action="forms.php" enctype="multipart/form-data" class="d-flex flex-wrap gap-2 align-items-center">
                        <?php csrf_field(); ?>
                        <input type="hidden" name="action" value="logo_upload">
                        <input type="file" class="form-control" style="max-width: 24rem;" name="logo" accept="image/png,image/jpeg" required
                               aria-label="<?= ff_e(M::get('forms.logo.file', 'Logo-Datei')) ?>">
                        <button type="submit" class="btn btn-primary"><?= ff_e($logoInfo !== null ? M::get('forms.logo.replace', 'Logo ersetzen') : M::get('forms.logo.upload', 'Logo hochladen')) ?></button>
                    </form>
                    <?php if ($logoInfo !== null): ?>
                        <form method="post" action="forms.php" class="mt-2" onsubmit="return confirm('<?= ff_e(M::get('forms.logo.confirm_remove', 'Das Logo wirklich entfernen?')) ?>');">
                            <?php csrf_field(); ?>
                            <input type="hidden" name="action" value="logo_delete">
                            <button type="submit" class="btn btn-sm btn-outline-danger"><?= ff_e(M::get('forms.logo.remove', 'Logo entfernen')) ?></button>
                        </form>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <div class="card mt-4" id="farbe">
        <div class="card-header"><h5 class="mb-0"><?= ff_e(M::get('forms.accent.title', 'Farbe der PDF-Bestätigungen')) ?></h5></div>
        <div class="card-body">
            <p class="text-muted"><?= ff_e(M::get('forms.accent.help', 'Die Farbe der Balken am linken Rand von Einleitung und Abschnitten in den PDF-Bestätigungen aller Formulare dieser Schule. Auswahl per Farbfeld oder als Hex-Wert (#RRGGBB).')) ?></p>
            <form method="post" action="forms.php" class="d-flex flex-wrap gap-2 align-items-center" id="accent-form">
                <?php csrf_field(); ?>
                <input type="hidden" name="action" value="accent_save">
                <input type="color" class="form-control form-control-color" id="accent-picker" value="<?= ff_e($accentShown) ?>"
                       title="<?= ff_e(M::get('forms.accent.pick', 'Farbe wählen')) ?>" aria-label="<?= ff_e(M::get('forms.accent.pick', 'Farbe wählen')) ?>">
                <input type="text" class="form-control font-monospace" style="max-width: 9rem;" id="accent-hex" name="accent_color" value="<?= ff_e($accentShown) ?>"
                       maxlength="7" pattern="#?[0-9A-Fa-f]{3}([0-9A-Fa-f]{3})?" placeholder="#3498db" spellcheck="false" autocomplete="off"
                       aria-label="<?= ff_e(M::get('forms.accent.hex', 'Hex-Wert')) ?>">
                <span class="border rounded p-2 bg-light small" style="border-left: 4px solid <?= ff_e($accentShown) ?> !important;" id="accent-sample">
                    <?= ff_e(M::get('forms.accent.sample', 'So sieht der Balken aus')) ?>
                </span>
                <button type="submit" class="btn btn-primary"><?= ff_e(M::get('forms.accent.save', 'Farbe speichern')) ?></button>
            </form>
            <?php if ($accentSaved !== null): ?>
                <form method="post" action="forms.php" class="mt-2">
                    <?php csrf_field(); ?>
                    <input type="hidden" name="action" value="accent_reset">
                    <button type="submit" class="btn btn-sm btn-outline-secondary"><?= ff_e(M::get('forms.accent.reset', 'Auf Standardfarbe zurücksetzen')) ?></button>
                </form>
            <?php endif; ?>
        </div>
    </div>
    <script>
    (function () {
        var picker = document.getElementById('accent-picker'), hex = document.getElementById('accent-hex'), sample = document.getElementById('accent-sample');
        if (!picker || !hex) { return; }
        function normalize(v) {
            v = v.trim().replace(/^#/, '');
            if (/^[0-9a-fA-F]{3}$/.test(v)) { v = v[0] + v[0] + v[1] + v[1] + v[2] + v[2]; }
            return /^[0-9a-fA-F]{6}$/.test(v) ? '#' + v.toLowerCase() : null;
        }
        picker.addEventListener('input', function () { hex.value = picker.value; sample.style.setProperty('border-left-color', picker.value, 'important'); });
        hex.addEventListener('input', function () {
            var c = normalize(hex.value);
            if (c) { picker.value = c; sample.style.setProperty('border-left-color', c, 'important'); }
        });
    })();
    </script>

    <?php if ($copySources !== []): ?>
    <div class="card mt-4">
        <div class="card-header"><h5 class="mb-0"><?= ff_e(M::get('forms.copy.title', 'Formulare von einem anderen Tenant übernehmen')) ?></h5></div>
        <div class="card-body">
            <form method="post" action="forms.php" class="row g-3 align-items-end">
                <?php csrf_field(); ?>
                <input type="hidden" name="action" value="copy">
                <div class="col-md-5">
                    <label class="form-label" for="copy_from"><?= ff_e(M::get('forms.copy.from', 'Von Tenant')) ?></label>
                    <select class="form-select" id="copy_from" name="copy_from" required>
                        <option value="">–</option>
                        <?php foreach ($copySources as $t): ?>
                            <option value="<?= (int)$t['id'] ?>"><?= ff_e($t['name']) ?> (<?= ff_e($t['slug']) ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-4"><div class="form-check">
                    <input class="form-check-input" type="checkbox" id="overwrite" name="overwrite" value="1">
                    <label class="form-check-label" for="overwrite"><?= ff_e(M::get('forms.copy.overwrite', 'Vorhandene Formulare ersetzen')) ?></label>
                </div></div>
                <div class="col-md-3"><button type="submit" class="btn btn-outline-primary"><?= ff_e(M::get('forms.copy.submit', 'Übernehmen')) ?></button></div>
                <div class="col-12 form-text"><?= ff_e(M::get('forms.copy.help', 'Kopiert Konfiguration, Survey und Theme. Nicht kopiert werden Empfänger-Adressen, das PDF-Logo, Anmeldungen, Entwürfe, Verlauf und Schlüssel.')) ?></div>
            </form>
        </div>
    </div>
    <?php endif; ?>

    <div class="card mt-4">
        <div class="card-header"><h5 class="mb-0"><?= ff_e(M::get('forms.new.title', 'Neues Formular anlegen')) ?></h5></div>
        <div class="card-body">
            <form method="post" action="forms.php" class="row g-3 align-items-start">
                <?php csrf_field(); ?>
                <input type="hidden" name="action" value="create">
                <div class="col-12 pb-0"><label class="form-label mb-0" for="form_key"><?= ff_e(M::get('forms.new.key', 'Schlüssel')) ?></label></div>
                <div class="col-md-6 mt-2">
                    <input type="text" id="form_key" name="form_key" class="form-control<?= isset($createErrors['form_key']) ? ' is-invalid' : '' ?>"
                           value="<?= ff_e($createKey) ?>" maxlength="64" pattern="[a-z0-9][a-z0-9_\-]*" required>
                    <div class="form-text"><?= ff_e(M::get('forms.new.key_help', 'Kleinbuchstaben, Ziffern, _ und -. Der Schlüssel steht in der Adresse (?form=…) und im WordPress-Shortcode [ondisos form="…"] und lässt sich später nicht mehr ändern.')) ?></div>
                    <?php foreach ($createErrors['form_key'] ?? [] as $m): ?><div class="invalid-feedback d-block"><?= ff_e($m) ?></div><?php endforeach; ?>
                    <?php foreach ($createErrors as $path => $messages): if ($path === 'form_key') { continue; } foreach ($messages as $m): ?>
                        <div class="invalid-feedback d-block"><?= ff_e($path . ': ' . $m) ?></div>
                    <?php endforeach; endforeach; ?>
                </div>
                <div class="col-md-6 mt-2">
                    <button type="submit" class="btn btn-primary"><?= ff_e(M::get('forms.new.submit', 'Anlegen')) ?></button>
                </div>
            </form>
        </div>
    </div>
<?php endif; ?>
</div>

<?php require __DIR__ . '/../inc/footer.php'; ?>
