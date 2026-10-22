<?php
// public/index.php

declare(strict_types=1);

require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/auth.php';
require_once __DIR__ . '/../inc/csrf.php';

use App\Controllers\AnmeldungController;
use App\Forms\ListColumns;
use App\Services\AnmeldungService;
use App\Services\ListColumnService;
use App\Repositories\AnmeldungRepository;
use App\Repositories\FormConfigRepository;
use App\Repositories\FormResourceRepository;
use App\Utils\NullableHelpers as NH;
use App\Services\MessageService as M;

// Initialize dependencies (later: use DI Container)
$repository = new AnmeldungRepository();
$service = new AnmeldungService($repository);
$controller = new AnmeldungController($service, new ListColumnService(new FormConfigRepository(), new FormResourceRepository()));

// Handle request
$viewData = $controller->index();

// Extract view data
extract($viewData);

/**
 * Build a URL for sorting a column, toggling direction if already active.
 *
 * @param string $column     The column to sort by
 * @param string $currentCol Currently active sort column
 * @param string $currentDir Currently active sort direction
 * @param array  $baseParams Base GET params to preserve (form, perPage, filters, ...)
 */
function sortUrl(string $column, string $currentCol, string $currentDir, array $baseParams): string
{
    $dir = ($currentCol === $column && $currentDir === 'ASC') ? 'DESC' : 'ASC';
    $params = array_merge($baseParams, ['sort' => $column, 'dir' => $dir, 'page' => 1]);
    return '?' . http_build_query(array_filter($params, fn($v) => $v !== ''));
}

/**
 * Render a sort indicator arrow for a column header.
 */
function sortIndicator(string $column, string $currentCol, string $currentDir): string
{
    if ($column !== $currentCol) {
        return '<span class="text-muted ms-1" style="font-size:.7em">&#8597;</span>';
    }
    return $currentDir === 'ASC'
        ? '<span class="ms-1" style="font-size:.75em">&#9650;</span>'
        : '<span class="ms-1" style="font-size:.75em">&#9660;</span>';
}
/**
 * Hidden inputs for GET params (arrays like cols[] and f[...] included).
 */
function hiddenInputs(array $params): string
{
    $html = '';
    foreach (explode('&', http_build_query($params)) as $pair) {
        if ($pair === '') {
            continue;
        }
        [$name, $value] = array_map('urldecode', array_pad(explode('=', $pair, 2), 2, ''));
        $html .= '<input type="hidden" name="' . htmlspecialchars($name) . '" value="' . htmlspecialchars($value) . '">';
    }
    return $html;
}

$isDataSort = str_starts_with($sortColumn, ListColumns::SORT_PREFIX);

// Get trash count for badge
$trashCount = count($repository->findDeleted());
require __DIR__ . '/../inc/header.php';
?>

<div class="container mt-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <div class="d-flex align-items-center gap-3">
            <h1 class="mb-0"><?= M::get('ui.anmeldungen') ?></h1>
            <form method="get" class="mb-0">
                <?php if (!$isDataSort && ($sortColumn !== 'id' || $sortDirection !== 'DESC')): ?>
                    <input type="hidden" name="sort" value="<?= htmlspecialchars($sortColumn) ?>">
                    <input type="hidden" name="dir" value="<?= htmlspecialchars($sortDirection) ?>">
                <?php endif; ?>
                <?php if ($nameSearch !== ''): ?>
                    <input type="hidden" name="name" value="<?= htmlspecialchars($nameSearch) ?>">
                <?php endif; ?>
                <?php if ($emailSearch !== ''): ?>
                    <input type="hidden" name="email" value="<?= htmlspecialchars($emailSearch) ?>">
                <?php endif; ?>
                <?php if ($selectedStatus !== ''): ?>
                    <input type="hidden" name="status" value="<?= htmlspecialchars($selectedStatus) ?>">
                <?php endif; ?>
                <select name="form" id="form" class="form-select" data-autosubmit>
                    <option value=""><?= M::get('ui.filters.all_forms') ?></option>
                    <?php foreach ($forms as $formKey): ?>
                        <option value="<?= htmlspecialchars($formKey) ?>"
                            <?= $formKey === $selectedForm ? 'selected' : '' ?>>
                            <?= htmlspecialchars($formKey) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </form>
        </div>
        <a href="trash.php" class="btn btn-outline-secondary">
            <?= M::get('ui.trash') ?>
            <?php if ($trashCount > 0): ?>
                <span class="badge bg-danger"><?= $trashCount ?></span>
            <?php endif; ?>
        </a>
    </div>

    <!-- Success Message -->
    <?php if (isset($_GET['bulk_success'])): ?>
        <div class="alert alert-success alert-dismissible fade show">
            <?= htmlspecialchars($_GET['bulk_message'] ?? M::get('success.bulk_action_completed', ['count' => '?', 'action' => ''])) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>
    
    <!-- Per Page Selector -->
    <form method="get" class="d-flex align-items-center gap-2 mb-3">
        <?php if ($selectedForm !== ''): ?>
            <input type="hidden" name="form" value="<?= htmlspecialchars($selectedForm) ?>">
        <?php endif; ?>
        <?php if ($sortColumn !== 'id' || $sortDirection !== 'DESC'): ?>
            <input type="hidden" name="sort" value="<?= htmlspecialchars($sortColumn) ?>">
            <input type="hidden" name="dir" value="<?= htmlspecialchars($sortDirection) ?>">
        <?php endif; ?>
        <?php if ($nameSearch !== ''): ?>
            <input type="hidden" name="name" value="<?= htmlspecialchars($nameSearch) ?>">
        <?php endif; ?>
        <?php if ($emailSearch !== ''): ?>
            <input type="hidden" name="email" value="<?= htmlspecialchars($emailSearch) ?>">
        <?php endif; ?>
        <?php if ($selectedStatus !== ''): ?>
            <input type="hidden" name="status" value="<?= htmlspecialchars($selectedStatus) ?>">
        <?php endif; ?>
        <?= hiddenInputs(array_filter(['cols' => $extraColumns, 'f' => $dataFilters])) ?>

        <label for="perPage" class="form-label mb-0"><?= M::get('ui.entries_per_page') ?></label>
        <select name="perPage" id="perPage" class="form-select form-select-sm w-auto" data-autosubmit>
            <?php foreach ($allowedPerPage as $n): ?>
                <option value="<?= $n ?>" <?= $n === $pagination['perPage'] ? 'selected' : '' ?>>
                    <?= $n ?>
                </option>
            <?php endforeach; ?>
        </select>
    </form>

    <?php
    // Base params preserved across sort/filter links (excludes sort/dir/page — those are set per link)
    $filterBase = [];
    if ($selectedForm !== '')  $filterBase['form']    = $selectedForm;
    if ($pagination['perPage'] !== 25) $filterBase['perPage'] = $pagination['perPage'];
    if ($nameSearch !== '')    $filterBase['name']    = $nameSearch;
    if ($emailSearch !== '')   $filterBase['email']   = $emailSearch;
    if ($selectedStatus !== '') $filterBase['status'] = $selectedStatus;
    if ($extraColumns !== [])  $filterBase['cols']    = $extraColumns;
    if ($dataFilters !== [])   $filterBase['f']       = $dataFilters;
    ?>
    <!-- Filter form: kept outside the bulk form (nested forms are invalid HTML and made the name filter submit the bulk form).
         The filter fields in the table header belong to it through their form="" attribute. -->
    <form method="get" id="filter-form">
        <?= hiddenInputs(array_intersect_key($filterBase, array_flip(['form', 'perPage', 'cols']))) ?>
        <input type="hidden" name="sort" value="<?= htmlspecialchars($sortColumn) ?>">
        <input type="hidden" name="dir" value="<?= htmlspecialchars($sortDirection) ?>">
        <input type="hidden" name="page" value="1">
    </form>
    <?php if ($columnCatalog !== []): ?>
        <!-- Column picker: extra columns from the survey data (checkboxes in the table header) -->
        <form method="get" id="columns-form">
            <?= hiddenInputs(array_diff_key($filterBase, ['cols' => true])) ?>
            <input type="hidden" name="sort" value="<?= htmlspecialchars($sortColumn) ?>">
            <input type="hidden" name="dir" value="<?= htmlspecialchars($sortDirection) ?>">
            <input type="hidden" name="cols_set" value="1">
        </form>
    <?php endif; ?>

    <!-- Bulk Actions Form -->
    <form method="post" action="bulk_actions.php" id="bulkForm">
        <?php csrf_field(); ?>
        <?php if ($selectedForm !== ''): ?>
            <input type="hidden" name="form" value="<?= htmlspecialchars($selectedForm) ?>">
        <?php endif; ?>
        
        <!-- Action Buttons -->
        <div class="d-flex gap-2 mb-3">
            <button type="button" class="btn btn-sm btn-warning" data-bulk="archive">
                <?= M::get('ui.buttons.archive') ?>
            </button>
            <!-- Set the status of the selected entries (same buttons as in the detail view) -->
            <div class="btn-group" role="group" aria-label="<?= htmlspecialchars(M::get('ui.detail.change_status', 'Status ändern')) ?>">
                <button type="button" class="btn btn-sm btn-warning" data-bulk="in_bearbeitung"
                        title="<?= htmlspecialchars(M::get('ui.detail.status_in_progress', 'In Bearbeitung')) ?>">📝</button>
                <button type="button" class="btn btn-sm btn-success" data-bulk="akzeptiert"
                        title="<?= htmlspecialchars(M::get('ui.detail.status_accepted', 'Akzeptiert')) ?>">☑️</button>
                <button type="button" class="btn btn-sm btn-danger" data-bulk="abgelehnt"
                        title="<?= htmlspecialchars(M::get('ui.detail.status_rejected', 'Abgelehnt')) ?>">👎</button>
            </div>
            <button type="button" class="btn btn-sm btn-danger" data-bulk="delete">
                <?= M::get('ui.buttons.delete') ?>
            </button>
            <button type="button" class="btn btn-sm btn-secondary" data-reload>
                🔄 Ansicht aktualisieren
            </button>
            <div class="ms-auto">
                <button type="button" class="btn btn-sm btn-success" data-export-excel>
                    <?= M::get('ui.buttons.excel_export') ?>
                </button>
            </div>
        </div>

        <!-- Data Table (scrolls sideways when extra columns make it wider than the page) -->
        <div class="table-responsive">
        <table class="table table-striped table-sm table-hover align-middle">
            <thead>
                <!-- Sort header row -->
                <tr>
                    <th style="width: 30px">
                        <input type="checkbox" id="selectAll" class="form-check-input">
                    </th>
                    <th>
                        <a href="<?= sortUrl('id', $sortColumn, $sortDirection, $filterBase) ?>" class="text-decoration-none text-dark fw-semibold">
                            <?= M::get('ui.table.id') ?><?= sortIndicator('id', $sortColumn, $sortDirection) ?>
                        </a>
                    </th>
                    <?php if ($selectedForm === ''): ?>
                    <th><?= M::get('ui.table.form') ?></th>
                    <?php endif; ?>
                    <th style="white-space: nowrap; min-width: 100px">Version</th>
                    <th>
                        <a href="<?= sortUrl('name', $sortColumn, $sortDirection, $filterBase) ?>" class="text-decoration-none text-dark fw-semibold">
                            <?= M::get('ui.table.name') ?><?= sortIndicator('name', $sortColumn, $sortDirection) ?>
                        </a>
                    </th>
                    <th>
                        <a href="<?= sortUrl('email', $sortColumn, $sortDirection, $filterBase) ?>" class="text-decoration-none text-dark fw-semibold">
                            <?= M::get('ui.table.email') ?><?= sortIndicator('email', $sortColumn, $sortDirection) ?>
                        </a>
                    </th>
                    <th>
                        <a href="<?= sortUrl('status', $sortColumn, $sortDirection, $filterBase) ?>" class="text-decoration-none text-dark fw-semibold">
                            <?= M::get('ui.table.status') ?><?= sortIndicator('status', $sortColumn, $sortDirection) ?>
                        </a>
                    </th>
                    <th style="white-space: nowrap; min-width: 140px">
                        <a href="<?= sortUrl('created_at', $sortColumn, $sortDirection, $filterBase) ?>" class="text-decoration-none text-dark fw-semibold">
                            <?= M::get('ui.table.date') ?><?= sortIndicator('created_at', $sortColumn, $sortDirection) ?>
                        </a>
                    </th>
                    <?php foreach ($extraColumns as $col): $sortKey = ListColumns::SORT_PREFIX . $col; ?>
                    <th>
                        <a href="<?= sortUrl($sortKey, $sortColumn, $sortDirection, $filterBase) ?>" class="text-decoration-none text-dark fw-semibold"
                           title="<?= htmlspecialchars($col) ?>">
                            <?= htmlspecialchars($columnCatalog[$col]['label']) ?><?= sortIndicator($sortKey, $sortColumn, $sortDirection) ?>
                        </a>
                    </th>
                    <?php endforeach; ?>
                    <?php if ($columnCatalog !== []): ?>
                    <th class="text-end" style="width: 1%">
                        <div class="dropdown">
                            <button type="button" class="btn btn-outline-secondary btn-sm dropdown-toggle" data-bs-toggle="dropdown"
                                    data-bs-auto-close="outside" data-bs-popper-config='{"strategy":"fixed"}' aria-expanded="false" title="<?= htmlspecialchars(M::get('ui.list_columns.hint')) ?>">
                                <?= M::get('ui.list_columns.button') ?>
                            </button>
                            <div class="dropdown-menu dropdown-menu-end p-0 fw-normal" style="min-width: 18rem; max-width: 24rem">
                                <!-- Header with the apply button stays in place; only the field list scrolls -->
                                <div class="d-flex align-items-start gap-2 p-3 pb-2 border-bottom">
                                    <div class="small text-muted flex-grow-1">
                                        <?= htmlspecialchars(M::get('ui.list_columns.hint')) ?>
                                        (<?= htmlspecialchars(M::format('ui.list_columns.max', ['max' => ListColumns::MAX_COLUMNS])) ?>)
                                    </div>
                                    <button type="submit" form="columns-form" class="btn btn-primary btn-sm">
                                        <?= M::get('ui.list_columns.apply') ?>
                                    </button>
                                </div>
                                <div class="px-3 py-2" style="max-height: 60vh; overflow-y: auto">
                                    <?php foreach ($columnCatalog as $name => $column): $name = (string)$name; $id = 'col-' . md5($name); ?>
                                        <div class="form-check">
                                            <input class="form-check-input" type="checkbox" name="cols[]" form="columns-form" id="<?= $id ?>"
                                                   value="<?= htmlspecialchars($name) ?>" <?= in_array($name, $extraColumns, true) ? 'checked' : '' ?>>
                                            <label class="form-check-label" for="<?= $id ?>" title="<?= htmlspecialchars($name) ?>">
                                                <?= htmlspecialchars($column['label']) ?>
                                            </label>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>
                    </th>
                    <?php endif; ?>
                </tr>
                <!-- Filter row -->
                <tr class="table-light">
                    <th></th>
                    <th></th><!-- ID: no filter -->
                    <?php if ($selectedForm === ''): ?><th></th><?php endif; ?>
                    <th></th><!-- Version: no filter -->
                    <th>
                        <input type="text" name="name" form="filter-form" value="<?= htmlspecialchars($nameSearch) ?>"
                                   class="form-control form-control-sm" placeholder="Filter…" style="min-width:100px">
                    </th>
                    <th>
                        <input type="text" name="email" form="filter-form" value="<?= htmlspecialchars($emailSearch) ?>"
                                   class="form-control form-control-sm" placeholder="Filter…" style="min-width:120px">
                    </th>
                    <th>
                        <select name="status" form="filter-form" class="form-select form-select-sm" data-autosubmit style="min-width:110px">
                                <option value="">Alle</option>
                                <?php foreach (\App\Models\AnmeldungStatus::cases() as $s): ?>
                                    <option value="<?= $s->value ?>" <?= $selectedStatus === $s->value ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($s->label()) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                    </th>
                    <th>
                        <?php
                        $hasFilters = $nameSearch !== '' || $emailSearch !== '' || $selectedStatus !== '' || $dataFilters !== [];
                        if ($hasFilters):
                            $resetParams = [];
                            if ($selectedForm !== '') $resetParams['form'] = $selectedForm;
                            if ($pagination['perPage'] !== 25) $resetParams['perPage'] = $pagination['perPage'];
                            if ($extraColumns !== []) $resetParams['cols'] = $extraColumns;
                        ?>
                            <a href="?<?= http_build_query($resetParams) ?>" class="btn btn-outline-secondary btn-sm" title="Filter zurücksetzen">&#10005;</a>
                        <?php endif; ?>
                    </th>
                    <?php foreach ($extraColumns as $col): $column = $columnCatalog[$col]; ?>
                    <th>
                        <?php if (ListColumns::filterable($column)): ?>
                            <select name="f[<?= htmlspecialchars($col) ?>]" form="filter-form" class="form-select form-select-sm" data-autosubmit style="min-width:100px">
                                <option value="">Alle</option>
                                <?php foreach ($column['choices'] as $key => $text): ?>
                                    <option value="<?= htmlspecialchars((string)$key) ?>" <?= ($dataFilters[$col] ?? null) === (string)$key ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($text) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        <?php endif; ?>
                    </th>
                    <?php endforeach; ?>
                    <?php if ($columnCatalog !== []): ?><th></th><?php endif; ?>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($anmeldungen as $anmeldung): ?>
                    <tr>
                        <td>
                            <input type="checkbox" name="ids[]" value="<?= $anmeldung->id ?>"
                                   class="form-check-input row-checkbox">
                        </td>
                        <td>
                            <a href="detail.php?id=<?= $anmeldung->id ?>">
                                #<?= $anmeldung->id ?>
                            </a>
                        </td>
                        <?php if ($selectedForm === ''): ?>
                            <td><?= NH::displayHtml($anmeldung->formular) ?></td>
                        <?php endif; ?>
                        <td><?= NH::displayHtml($anmeldung->formularVersion, 'v1.0') ?></td>
                        <td><?= NH::displayHtml($anmeldung->name, 'Unbekannt') ?></td>
                        <td><?= NH::displayHtml($anmeldung->email) ?></td>
                        <td>
                            <?php
                            $statusEnum = \App\Models\AnmeldungStatus::tryFromString($anmeldung->status);
                            $badgeClass = $statusEnum?->badgeClass() ?? 'badge bg-secondary';
                            $statusLabel = $statusEnum?->label() ?? $anmeldung->status;
                            ?>
                            <span class="<?= $badgeClass ?>">
                                <?= htmlspecialchars($statusLabel) ?>
                            </span>
                        </td>
                        <td><?= $anmeldung->createdAt->format('d.m.Y H:i') ?></td>
                        <?php foreach ($extraColumns as $col): $text = ListColumns::format($anmeldung->data[$col] ?? null, $columnCatalog[$col]); ?>
                            <td class="text-truncate" style="max-width: 16rem" title="<?= htmlspecialchars($text) ?>"><?= htmlspecialchars($text) ?></td>
                        <?php endforeach; ?>
                        <?php if ($columnCatalog !== []): ?><td></td><?php endif; ?>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    </form>

    <!-- Pagination -->
    <nav>
        <ul class="pagination">
            <?php
            $pageBase = [];
            if ($selectedForm !== '')    $pageBase['form']    = $selectedForm;
            if ($pagination['perPage'] !== 25) $pageBase['perPage'] = $pagination['perPage'];
            if ($nameSearch !== '')      $pageBase['name']    = $nameSearch;
            if ($emailSearch !== '')     $pageBase['email']   = $emailSearch;
            if ($selectedStatus !== '')  $pageBase['status']  = $selectedStatus;
            if ($extraColumns !== [])    $pageBase['cols']    = $extraColumns;
            if ($dataFilters !== [])     $pageBase['f']       = $dataFilters;
            if ($sortColumn !== 'id' || $sortDirection !== 'DESC') {
                $pageBase['sort'] = $sortColumn;
                $pageBase['dir']  = $sortDirection;
            }

            for ($i = 1; $i <= $pagination['totalPages']; $i++):
                $pageBase['page'] = $i;
                $url = '?' . http_build_query($pageBase);
            ?>
                <li class="page-item <?= $i === $pagination['page'] ? 'active' : '' ?>">
                    <a class="page-link" href="<?= $url ?>"><?= $i ?></a>
                </li>
            <?php endfor; ?>
        </ul>
    </nav>
</div>

<script nonce="<?= \App\Utils\ContentSecurityPolicy::nonce() ?>">
// Buttons der Sammelaktionen (statt Inline-Handlern, siehe ContentSecurityPolicy)
document.addEventListener('click', function (event) {
    const bulk = event.target.closest('[data-bulk]');
    if (bulk) { bulkAction(bulk.getAttribute('data-bulk')); return; }
    if (event.target.closest('[data-export-excel]')) { exportExcel(); }
});

// Select All checkbox
document.getElementById('selectAll').addEventListener('change', function() {
    const checkboxes = document.querySelectorAll('.row-checkbox');
    checkboxes.forEach(cb => cb.checked = this.checked);
});

// Excel export: exports selected rows, or all rows if nothing is selected
function exportExcel() {
    const checkboxes = document.querySelectorAll('.row-checkbox:checked');

    if (checkboxes.length === 0) {
        // Nothing selected: export all / filtered (POST with CSRF token)
        const exportForm = document.createElement('form');
        exportForm.method = 'POST';
        exportForm.action = 'excel_export.php';
        const fields = {
            csrf_token: document.getElementById('bulkForm').querySelector('[name=csrf_token]').value,
            form: <?= json_encode((string)($_GET['form'] ?? ''), JSON_HEX_TAG | JSON_HEX_AMP) ?>
        };
        for (const [name, value] of Object.entries(fields)) {
            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = name;
            input.value = value;
            exportForm.appendChild(input);
        }
        document.body.appendChild(exportForm);
        exportForm.submit();
        document.body.removeChild(exportForm);
        return;
    }

    // IDs selected: POST them via the bulk form so CSRF token is included
    const form = document.getElementById('bulkForm');
    const prevAction = form.action;

    form.action = 'excel_export.php';

    const marker = document.createElement('input');
    marker.type = 'hidden';
    marker.name = 'export_selected';
    marker.value = '1';
    form.appendChild(marker);

    form.submit();

    // Cleanup (browser stays on page because response is a file download)
    form.action = prevAction;
    form.removeChild(marker);
}

// Bulk action handler
function bulkAction(action) {
    const form = document.getElementById('bulkForm');
    const checkboxes = document.querySelectorAll('.row-checkbox:checked');

    if (checkboxes.length === 0) {
        alert('<?= M::get('errors.no_entries_selected') ?>');
        return;
    }

    const statusLabels = <?= json_encode([
        'in_bearbeitung' => M::get('status.in_bearbeitung'),
        'akzeptiert'     => M::get('status.akzeptiert'),
        'abgelehnt'      => M::get('status.abgelehnt'),
    ], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>;
    let confirmMsg;
    if (statusLabels[action]) {
        confirmMsg = `Möchten Sie ${checkboxes.length} Einträge wirklich auf „${statusLabels[action]}“ setzen?`;
    } else {
        const actionLabel = action === 'archive' ? '<?= M::get('bulk_actions.archive') ?>' : '<?= M::get('bulk_actions.delete') ?>';
        confirmMsg = `Möchten Sie ${checkboxes.length} Einträge wirklich ${actionLabel.toLowerCase()}?`;
    }

    if (!confirm(confirmMsg)) {
        return;
    }
    
    // Add action to form
    const actionInput = document.createElement('input');
    actionInput.type = 'hidden';
    actionInput.name = 'action';
    actionInput.value = action;
    form.appendChild(actionInput);
    
    form.submit();
}
</script>

<?php require __DIR__ . '/../inc/footer.php'; ?>