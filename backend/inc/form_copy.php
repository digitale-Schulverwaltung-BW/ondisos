<?php
declare(strict_types=1);

/**
 * Shared by tenants.php and forms.php: copying forms between tenants (platform admins only).
 */

use App\Config\Database;
use App\Forms\AccessDeniedException;
use App\Forms\FormConfigSchema;
use App\Repositories\FormConfigRepository;
use App\Repositories\FormResourceRepository;
use App\Repositories\FormRevisionRepository;
use App\Repositories\TenantRepository;
use App\Services\FormCopyService;
use App\Services\MessageService as M;

function form_copy_service(): FormCopyService
{
    $db = Database::getConnection();
    return new FormCopyService($db, new TenantRepository($db), new FormConfigRepository($db), new FormResourceRepository($db), new FormRevisionRepository($db));
}

/**
 * Run a copy for the logged-in platform admin and remember the report for the next page view.
 * Never throws: problems become a flash message.
 */
function form_copy_run(int $fromTenantId, int $toTenantId, bool $overwrite): bool
{
    require_once __DIR__ . '/editor_rate_limit.php';
    editor_rate_limit();

    $role = !empty($_SESSION['is_platform_admin']) || !filter_var(\App\Config\EnvLoader::get('MULTI_TENANT_ENABLED', 'false'), FILTER_VALIDATE_BOOLEAN)
        ? FormConfigSchema::ROLE_PLATFORM
        : FormConfigSchema::ROLE_TENANT;

    try {
        $report = form_copy_service()->copy($fromTenantId, $toTenantId, null, $overwrite, $role, (string)($_SESSION['admin_username'] ?? 'admin'));
    } catch (AccessDeniedException $e) {
        http_response_code(403);
        die(htmlspecialchars($e->getMessage()));
    } catch (\InvalidArgumentException $e) {
        $_SESSION['copy_flash'] = ['danger', $e->getMessage()];
        return false;
    } catch (\Throwable $e) {
        error_log('form copy failed: ' . $e->getMessage());
        $_SESSION['copy_flash'] = ['danger', M::get('forms.copy.failed', 'Das Kopieren ist fehlgeschlagen. Es wurde nichts kopiert.')];
        return false;
    }

    $_SESSION['copy_report'] = $report;
    return true;
}

/** @return array{0: ?array<string,mixed>, 1: ?array{0:string,1:string}} [report, flash] — both cleared */
function form_copy_take(): array
{
    $report = $_SESSION['copy_report'] ?? null;
    $flash  = $_SESSION['copy_flash'] ?? null;
    unset($_SESSION['copy_report'], $_SESSION['copy_flash']);
    return [is_array($report) ? $report : null, is_array($flash) ? $flash : null];
}

/** HTML of one copy report (all values escaped). */
function form_copy_report_html(array $report): string
{
    $e = static fn (mixed $v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
    $labels = [
        FormCopyService::STATUS_COPIED      => ['success', M::get('forms.copy.status.copied', 'kopiert')],
        FormCopyService::STATUS_OVERWRITTEN => ['warning', M::get('forms.copy.status.overwritten', 'ersetzt')],
        FormCopyService::STATUS_SKIPPED     => ['secondary', M::get('forms.copy.status.skipped', 'übersprungen (gibt es schon)')],
        FormCopyService::STATUS_MISSING     => ['secondary', M::get('forms.copy.status.missing', 'nicht gefunden')],
        FormCopyService::STATUS_INVALID     => ['danger', M::get('forms.copy.status.invalid', 'nicht kopiert (ungültig)')],
    ];

    $html = '<div class="card mb-4"><div class="card-header"><h5 class="mb-0">'
        . $e(M::format('forms.copy.report_title', ['from' => $report['from']['name'], 'to' => $report['to']['name']], 'Kopiert von „{{from}}" nach „{{to}}"')) . '</h5></div>'
        . '<ul class="list-group list-group-flush">';

    if ($report['forms'] === []) {
        $html .= '<li class="list-group-item text-muted">' . $e(M::get('forms.copy.no_forms', 'Der Quell-Tenant hat keine Formulare.')) . '</li>';
    }
    $needsRecipient = false;
    foreach ($report['forms'] as $key => $f) {
        [$color, $label] = $labels[$f['status']] ?? ['secondary', $f['status']];
        $html .= '<li class="list-group-item"><code>' . $e($key) . '</code> <span class="badge text-bg-' . $color . '">' . $e($label) . '</span>';
        $copied = in_array($f['status'], [FormCopyService::STATUS_COPIED, FormCopyService::STATUS_OVERWRITTEN], true);
        if ($copied && $f['recipient_cleared']) {
            $needsRecipient = true;
            $html .= '<div class="small text-warning-emphasis mt-1">⚠ ' . $e(M::get('forms.copy.recipient_missing', 'Bitte prüfen: Empfänger fehlt (wurde bewusst nicht kopiert).'))
                . ($f['hidden_until_recipient'] ? ' ' . $e(M::get('forms.copy.hidden', 'Das Formular wird erst angezeigt, wenn ein Empfänger eingetragen ist.')) : '') . '</div>';
        }
        if ($copied && $f['review'] !== []) {
            $names = array_map(static fn (string $p): string => M::get('forms.fields.' . $p . '.label', $p), $f['review']);
            $html .= '<div class="small text-muted mt-1">' . $e(M::get('forms.copy.review', 'Bitte Texte prüfen (Name/Kontakt der anderen Schule?):')) . ' ' . $e(implode(', ', $names)) . '</div>';
        }
        foreach ($f['warnings'] as $w) {
            $html .= '<div class="small text-muted mt-1">⚠ ' . $e($w['path'] . ': ' . $w['message']) . '</div>';
        }
        foreach ($f['errors'] as $err) {
            $html .= '<div class="small text-danger mt-1">' . $e(($err['path'] !== '' ? $err['path'] . ': ' : '') . $err['message']) . '</div>';
        }
        $html .= '</li>';
    }
    $html .= '</ul>';

    if ($needsRecipient) {
        $html .= '<div class="card-footer">' . $e(M::get('forms.copy.next', 'Nächster Schritt: unter „Formulare" je Formular den Empfänger der neuen Schule eintragen.'))
            . ' <a href="forms.php">' . $e(M::get('forms.title', 'Formulare')) . '</a></div>';
    }
    return $html . '</div>';
}
