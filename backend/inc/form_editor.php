<?php
declare(strict_types=1);

/**
 * Shared start of forms.php and form_edit.php: access check, tenant check, controller.
 *
 * Sets: $editorRole (platform|tenant), $editor (FormEditorController) — or $editorNoTenant = true when a
 * platform admin is in "all tenants" mode and has to pick a tenant first (then $editor is not set).
 * Stops with 403 for everybody who may not use the editor.
 */

use App\Config\Database;
use App\Config\EnvLoader;
use App\Config\TenantContext;
use App\Controllers\FormEditorController;
use App\Forms\EditorAccess;
use App\Repositories\FormConfigRepository;
use App\Repositories\FormDraftRepository;
use App\Repositories\FormResourceRepository;
use App\Repositories\FormRevisionRepository;
use App\Services\FormPublishService;

$editorMultiTenant = filter_var(EnvLoader::get('MULTI_TENANT_ENABLED', 'false'), FILTER_VALIDATE_BOOLEAN);
$editorRole = EditorAccess::roleFor($_SESSION, $editorMultiTenant);

if ($editorRole === null) {
    http_response_code(403);
    die('Kein Zugriff.');
}

$editorNoTenant = TenantContext::isAllTenants();
if (!$editorNoTenant) {
    $db       = Database::getConnection();
    $configs  = new FormConfigRepository($db);
    $resources = new FormResourceRepository($db);
    $drafts   = new FormDraftRepository($db);
    $revisions = new FormRevisionRepository($db);
    $editor   = new FormEditorController(
        $configs,
        $resources,
        $drafts,
        $revisions,
        new FormPublishService($db, $configs, $resources, $drafts, $revisions),
        $editorRole,
        (string)($_SESSION['admin_username'] ?? 'admin'),
    );
}

/** Flash message for the next page view: ['success'|'danger'|'warning', text]. */
function editor_flash(string $type, string $message): void
{
    $_SESSION['editor_flash'][] = [$type, $message];
}

/** @return list<array{0:string,1:string}> and clears them */
function editor_take_flash(): array
{
    $flash = $_SESSION['editor_flash'] ?? [];
    unset($_SESSION['editor_flash']);
    return is_array($flash) ? $flash : [];
}
