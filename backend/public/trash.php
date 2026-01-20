<?php
// public/trash.php

declare(strict_types=1);

require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/auth.php';

use App\Repositories\AnmeldungRepository;
use App\Services\AnmeldungService;
use App\Controllers\AnmeldungController;
use App\Utils\NullableHelpers as NH;
use App\Services\MessageService as M;

// Initialize dependencies
$repository = new AnmeldungRepository();

// Get deleted entries
$deletedEntries = $repository->findDeleted();

require __DIR__ . '/../inc/header.php';
?>

<div class="container mt-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1><?= M::get('ui.trash.title', '🗑️ Papierkorb') ?></h1>
        <a href="index.php" class="btn btn-secondary"><?= M::get('ui.back_to_overview') ?></a>
    </div>

    <!-- Success/Error Messages -->
    <?php if (isset($_GET['restored'])): ?>
        <div class="alert alert-success alert-dismissible fade show">
            <?= M::format('success.restored', ['id' => (int)($_GET['id'] ?? 0)]) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <?php if (isset($_GET['hard_deleted'])): ?>
        <div class="alert alert-warning alert-dismissible fade show">
            <?= M::format('success.deleted', ['id' => (int)($_GET['id'] ?? 0)]) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <?php if (isset($_GET['error'])): ?>
        <div class="alert alert-danger alert-dismissible fade show">
            <?php
            echo match($_GET['error']) {
                'not_found' => M::get('errors.not_found'),
                'failed' => M::get('errors.delete_failed'),
                default => M::withContact('errors.generic_error')
            };
            ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <?php if (empty($deletedEntries)): ?>
        <div class="alert alert-info">
            <strong><?= M::get('ui.trash.empty') ?></strong>
            <p class="mb-0"><?= M::get('ui.trash.empty_description', 'Gelöschte Einträge erscheinen hier.') ?></p>
        </div>
    <?php else: ?>
        <div class="alert alert-warning">
            <strong><?= M::get('ui.warning') ?></strong>
            <?= M::get('ui.trash.warning_description', 'Diese Einträge wurden gelöscht und sind für normale Benutzer nicht sichtbar.') ?>
            <?php
            $config = \App\Config\Config::getInstance();
            if ($config->autoExpungeDays > 0):
            ?>
                <?= M::format('ui.trash.auto_delete_info', ['days' => $config->autoExpungeDays]) ?>
            <?php endif; ?>
        </div>

        <div class="mb-3">
            <small class="text-muted"><?= M::format('ui.trash.entry_count', ['count' => count($deletedEntries)]) ?></small>
        </div>

        <table class="table table-striped table-sm">
            <thead>
                <tr>
                    <th><?= M::get('ui.table.id') ?></th>
                    <th><?= M::get('ui.table.form') ?></th>
                    <th><?= M::get('ui.table.name') ?></th>
                    <th><?= M::get('ui.table.email') ?></th>
                    <th><?= M::get('ui.table.status') ?></th>
                    <th><?= M::get('ui.table.created_at') ?></th>
                    <th><?= M::get('ui.table.deleted_at') ?></th>
                    <th><?= M::get('ui.table.actions') ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($deletedEntries as $entry): ?>
                    <tr class="table-danger">
                        <td>
                            <a href="detail.php?id=<?= $entry->id ?>&show_deleted=1">
                                #<?= $entry->id ?>
                            </a>
                        </td>
                        <td><?= NH::displayHtml($entry->formular) ?></td>
                        <td><?= NH::displayHtml($entry->name, 'Unbekannt') ?></td>
                        <td><?= NH::displayHtml($entry->email) ?></td>
                        <td>
                            <?php 
                            $statusEnum = \App\Models\AnmeldungStatus::tryFromString($entry->status);
                            $badgeClass = $statusEnum?->badgeClass() ?? 'badge bg-secondary';
                            $statusLabel = $statusEnum?->label() ?? $entry->status;
                            ?>
                            <span class="<?= $badgeClass ?>">
                                <?= htmlspecialchars($statusLabel) ?>
                            </span>
                        </td>
                        <td><?= $entry->createdAt->format('d.m.Y H:i') ?></td>
                        <td>
                            <?php if ($entry->deletedAt): ?>
                                <?= $entry->deletedAt->format('d.m.Y H:i') ?>
                            <?php else: ?>
                                -
                            <?php endif; ?>
                        </td>
                        <td>
                            <form method="post" action="restore.php" style="display: inline;">
                                <input type="hidden" name="id" value="<?= $entry->id ?>">
                                <button type="submit" class="btn btn-sm btn-success"
                                        onclick="return confirm('<?= M::format('ui.trash.confirm_restore', ['id' => $entry->id]) ?>')">
                                    <?= M::get('ui.buttons.restore') ?>
                                </button>
                            </form>
                            <form method="post" action="hard_delete.php" style="display: inline;">
                                <input type="hidden" name="id" value="<?= $entry->id ?>">
                                <button type="submit" class="btn btn-sm btn-danger"
                                        onclick="return confirm('<?= M::get('ui.trash.confirm_hard_delete') ?>')">
                                    <?= M::get('ui.buttons.hard_delete') ?>
                                </button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>

<?php require __DIR__ . '/../inc/footer.php'; ?>