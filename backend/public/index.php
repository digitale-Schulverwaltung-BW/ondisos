<?php
// public/index.php

declare(strict_types=1);

require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/auth.php';

use App\Controllers\AnmeldungController;
use App\Services\AnmeldungService;
use App\Repositories\AnmeldungRepository;
use App\Utils\NullableHelpers as NH;

// Initialize dependencies (later: use DI Container)
$repository = new AnmeldungRepository();
$service = new AnmeldungService($repository);
$controller = new AnmeldungController($service);

// Handle request
$viewData = $controller->index();

// Extract view data
extract($viewData);
// Get trash count for badge
$trashCount = count($repository->findDeleted());
require __DIR__ . '/../inc/header.php';
?>

<div class="container mt-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <div class="d-flex align-items-center gap-3">
            <h1 class="mb-0">Anmeldungen</h1>
            <form method="get" class="mb-0">
                <select name="form" id="form" class="form-select" onchange="this.form.submit()">
                    <option value="">Alle Formulare</option>
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
            🗑️ Papierkorb
            <?php if ($trashCount > 0): ?>
                <span class="badge bg-danger"><?= $trashCount ?></span>
            <?php endif; ?>
        </a>
    </div>
    
    <!-- Success Message -->
    <?php if (isset($_GET['bulk_success'])): ?>
        <div class="alert alert-success alert-dismissible fade show">
            <?= htmlspecialchars($_GET['bulk_message'] ?? 'Aktion erfolgreich durchgeführt') ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>
    
    <!-- Per Page Selector -->
    <form method="get" class="d-flex align-items-center gap-2 mb-3">
        <?php if ($selectedForm !== ''): ?>
            <input type="hidden" name="form" value="<?= htmlspecialchars($selectedForm) ?>">
        <?php endif; ?>

        <label for="perPage" class="form-label mb-0">Einträge pro Seite:</label>
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
        <?php if ($selectedForm !== ''): ?>
            <input type="hidden" name="form" value="<?= htmlspecialchars($selectedForm) ?>">
        <?php endif; ?>
        
        <!-- Action Buttons -->
        <div class="d-flex gap-2 mb-3">
            <button type="button" class="btn btn-sm btn-warning" onclick="bulkAction('archive')">
                📦 Archivieren
            </button>
            <button type="button" class="btn btn-sm btn-danger" onclick="bulkAction('delete')">
                🗑️ Löschen
            </button>
            <button type="button" class="btn btn-sm btn-secondary" onclick="window.location.reload()">
                🔄 Ansicht aktualisieren
            </button>
            <div class="ms-auto">
                <a href="excel_export.php?<?= http_build_query($_GET) ?>" class="btn btn-sm btn-success">
                    📥 Excel-Export
                </a>
            </div>
        </div>

        <!-- Data Table -->
        <table class="table table-striped table-sm table-hover">
            <thead>
                <tr>
                    <th style="width: 30px">
                        <input type="checkbox" id="selectAll" class="form-check-input">
                    </th>
                    <th>ID</th>
                    <?php if ($selectedForm === ''): ?><th>Formular</th><?php endif; ?>
                    <th>Version</th>
                    <th>Name</th>
                    <th>E-Mail</th>
                    <th>Status</th>
                    <th>Datum</th>
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
            $baseParams = [
                'perPage' => $pagination['perPage']
            ];
            if ($selectedForm !== '') {
                $baseParams['form'] = $selectedForm;
            }

            for ($i = 1; $i <= $pagination['totalPages']; $i++):
                $baseParams['page'] = $i;
                $url = '?' . http_build_query($baseParams);
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

// Bulk action handler
function bulkAction(action) {
    const form = document.getElementById('bulkForm');
    const checkboxes = document.querySelectorAll('.row-checkbox:checked');
    
    if (checkboxes.length === 0) {
        alert('Bitte wählen Sie mindestens einen Eintrag aus.');
        return;
    }
    
    const actionLabel = action === 'archive' ? 'archivieren' : 'löschen';
    const confirmMsg = `Möchten Sie ${checkboxes.length} Einträge wirklich ${actionLabel}?`;
    
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