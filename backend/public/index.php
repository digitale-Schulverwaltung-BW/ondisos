<?php
// public/index.php

declare(strict_types=1);

require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/auth.php';
require_once __DIR__ . '/../inc/csrf.php';

use App\Controllers\AnmeldungController;
use App\Services\AnmeldungService;
use App\Repositories\AnmeldungRepository;
use App\Utils\NullableHelpers as NH;
use App\Services\MessageService as M;

// Initialize dependencies (later: use DI Container)
$repository = new AnmeldungRepository();
$service = new AnmeldungService($repository);
$controller = new AnmeldungController($service);

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
// Get trash count for badge
$trashCount = count($repository->findDeleted());
require __DIR__ . '/../inc/header.php';
?>

<div class="container mt-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <div class="d-flex align-items-center gap-3">
            <h1 class="mb-0"><?= M::get('ui.anmeldungen') ?></h1>
            <form method="get" class="mb-0">
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
                <select name="form" id="form" class="form-select" onchange="this.form.submit()">
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

        <label for="perPage" class="form-label mb-0"><?= M::get('ui.entries_per_page') ?></label>
        <select name="perPage" id="perPage" class="form-select form-select-sm w-auto" onchange="this.form.submit()">
            <?php foreach ($allowedPerPage as $n): ?>
                <option value="<?= $n ?>" <?= $n === $pagination['perPage'] ? 'selected' : '' ?>>
                    <?= $n ?>
                </option>
            <?php endforeach; ?>
        </select>
    </form>

    <!-- Bulk Actions Form -->
    <form method="post" action="bulk_actions.php" id="bulkForm">
        <?php csrf_field(); ?>
        <?php if ($selectedForm !== ''): ?>
            <input type="hidden" name="form" value="<?= htmlspecialchars($selectedForm) ?>">
        <?php endif; ?>
        
        <!-- Action Buttons -->
        <div class="d-flex gap-2 mb-3">
            <button type="button" class="btn btn-sm btn-warning" onclick="bulkAction('archive')">
                <?= M::get('ui.buttons.archive') ?>
            </button>
            <button type="button" class="btn btn-sm btn-danger" onclick="bulkAction('delete')">
                <?= M::get('ui.buttons.delete') ?>
            </button>
            <button type="button" class="btn btn-sm btn-secondary" onclick="window.location.reload()">
                🔄 Ansicht aktualisieren
            </button>
            <div class="ms-auto">
                <button type="button" class="btn btn-sm btn-success" onclick="exportExcel()">
                    <?= M::get('ui.buttons.excel_export') ?>
                </button>
            </div>
        </div>

        <?php
        // Base params preserved across sort/filter links (excludes sort/dir/page — those are set per link)
        $filterBase = [];
        if ($selectedForm !== '')  $filterBase['form']    = $selectedForm;
        if ($pagination['perPage'] !== 25) $filterBase['perPage'] = $pagination['perPage'];
        if ($nameSearch !== '')    $filterBase['name']    = $nameSearch;
        if ($emailSearch !== '')   $filterBase['email']   = $emailSearch;
        if ($selectedStatus !== '') $filterBase['status'] = $selectedStatus;
        ?>

        <!-- Data Table -->
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
                    <th style="white-space: nowrap min-width: 140px">
                        <a href="<?= sortUrl('created_at', $sortColumn, $sortDirection, $filterBase) ?>" class="text-decoration-none text-dark fw-semibold">
                            <?= M::get('ui.table.date') ?><?= sortIndicator('created_at', $sortColumn, $sortDirection) ?>
                        </a>
                    </th>
                </tr>
                <!-- Filter row -->
                <tr class="table-light">
                    <th></th>
                    <th></th><!-- ID: no filter -->
                    <?php if ($selectedForm === ''): ?><th></th><?php endif; ?>
                    <th></th><!-- Version: no filter -->
                    <th>
                        <form method="get" class="mb-0">
                            <?php foreach ($filterBase as $k => $v): if ($k === 'name') continue; ?>
                                <input type="hidden" name="<?= htmlspecialchars($k) ?>" value="<?= htmlspecialchars((string)$v) ?>">
                            <?php endforeach; ?>
                            <input type="hidden" name="sort" value="<?= htmlspecialchars($sortColumn) ?>">
                            <input type="hidden" name="dir" value="<?= htmlspecialchars($sortDirection) ?>">
                            <input type="hidden" name="page" value="1">
                            <input type="text" name="name" value="<?= htmlspecialchars($nameSearch) ?>"
                                   class="form-control form-control-sm" placeholder="Filter…" style="min-width:100px">
                        </form>
                    </th>
                    <th>
                        <form method="get" class="mb-0">
                            <?php foreach ($filterBase as $k => $v): if ($k === 'email') continue; ?>
                                <input type="hidden" name="<?= htmlspecialchars($k) ?>" value="<?= htmlspecialchars((string)$v) ?>">
                            <?php endforeach; ?>
                            <input type="hidden" name="sort" value="<?= htmlspecialchars($sortColumn) ?>">
                            <input type="hidden" name="dir" value="<?= htmlspecialchars($sortDirection) ?>">
                            <input type="hidden" name="page" value="1">
                            <input type="text" name="email" value="<?= htmlspecialchars($emailSearch) ?>"
                                   class="form-control form-control-sm" placeholder="Filter…" style="min-width:120px">
                        </form>
                    </th>
                    <th>
                        <form method="get" class="mb-0">
                            <?php foreach ($filterBase as $k => $v): if ($k === 'status') continue; ?>
                                <input type="hidden" name="<?= htmlspecialchars($k) ?>" value="<?= htmlspecialchars((string)$v) ?>">
                            <?php endforeach; ?>
                            <input type="hidden" name="sort" value="<?= htmlspecialchars($sortColumn) ?>">
                            <input type="hidden" name="dir" value="<?= htmlspecialchars($sortDirection) ?>">
                            <input type="hidden" name="page" value="1">
                            <select name="status" class="form-select form-select-sm" onchange="this.form.submit()" style="min-width:110px">
                                <option value="">Alle</option>
                                <?php foreach (\App\Models\AnmeldungStatus::cases() as $s): ?>
                                    <option value="<?= $s->value ?>" <?= $selectedStatus === $s->value ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($s->label()) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </form>
                    </th>
                    <th>
                        <?php
                        $hasFilters = $nameSearch !== '' || $emailSearch !== '' || $selectedStatus !== '';
                        if ($hasFilters):
                            $resetParams = [];
                            if ($selectedForm !== '') $resetParams['form'] = $selectedForm;
                            if ($pagination['perPage'] !== 25) $resetParams['perPage'] = $pagination['perPage'];
                        ?>
                            <a href="?<?= http_build_query($resetParams) ?>" class="btn btn-outline-secondary btn-sm" title="Filter zurücksetzen">&#10005;</a>
                        <?php endif; ?>
                    </th>
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
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </form>

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

<script>
// Select All checkbox
document.getElementById('selectAll').addEventListener('change', function() {
    const checkboxes = document.querySelectorAll('.row-checkbox');
    checkboxes.forEach(cb => cb.checked = this.checked);
});

// Excel export: exports selected rows, or all rows if nothing is selected
function exportExcel() {
    const checkboxes = document.querySelectorAll('.row-checkbox:checked');

    if (checkboxes.length === 0) {
        // Nothing selected: fall back to regular GET export (exports all / filtered)
        window.location.href = 'excel_export.php?<?= http_build_query($_GET) ?>';
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

    const actionLabel = action === 'archive' ? '<?= M::get('bulk_actions.archive') ?>' : '<?= M::get('bulk_actions.delete') ?>';
    const confirmMsg = `Möchten Sie ${checkboxes.length} Einträge wirklich ${actionLabel.toLowerCase()}?`;

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