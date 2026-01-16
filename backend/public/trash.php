<?php
// public/trash.php

declare(strict_types=1);

require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/auth.php';

use App\Repositories\AnmeldungRepository;
use App\Services\AnmeldungService;
use App\Controllers\AnmeldungController;
use App\Utils\NullableHelpers as NH;

// Initialize dependencies
$repository = new AnmeldungRepository();

// Get deleted entries
$deletedEntries = $repository->findDeleted();

require __DIR__ . '/../inc/header.php';
?>

<div class="container mt-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1>🗑️ Papierkorb</h1>
        <a href="index.php" class="btn btn-secondary">← Zurück zur Übersicht</a>
    </div>

    <!-- Success/Error Messages -->
    <?php if (isset($_GET['restored'])): ?>
        <div class="alert alert-success alert-dismissible fade show">
            <strong>✓ Wiederhergestellt!</strong> 
            Eintrag #<?= (int)($_GET['id'] ?? 0) ?> wurde wiederhergestellt.
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <?php if (isset($_GET['hard_deleted'])): ?>
        <div class="alert alert-warning alert-dismissible fade show">
            <strong>⚠️ Permanent gelöscht!</strong> 
            Eintrag #<?= (int)($_GET['id'] ?? 0) ?> wurde permanent aus der Datenbank entfernt.
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <?php if (isset($_GET['error'])): ?>
        <div class="alert alert-danger alert-dismissible fade show">
            <strong>❌ Fehler!</strong> 
            <?php
            echo match($_GET['error']) {
                'not_found' => 'Eintrag wurde nicht gefunden.',
                'failed' => 'Die Aktion konnte nicht durchgeführt werden.',
                default => 'Ein unbekannter Fehler ist aufgetreten.'
            };
            ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <?php if (empty($deletedEntries)): ?>
        <div class="alert alert-info">
            <strong>ℹ️ Der Papierkorb ist leer</strong>
            <p class="mb-0">Gelöschte Einträge erscheinen hier.</p>
        </div>
    <?php else: ?>
        <div class="alert alert-warning">
            <strong>⚠️ Hinweis:</strong> 
            Diese Einträge wurden gelöscht und sind für normale Benutzer nicht sichtbar.
            <?php 
            $config = \App\Config\Config::getInstance();
            if ($config->autoExpungeDays > 0): 
            ?>
                Sie werden nach <strong><?= $config->autoExpungeDays ?> Tagen</strong> permanent gelöscht.
            <?php endif; ?>
        </div>

        <div class="mb-3">
            <small class="text-muted">Anzahl: <?= count($deletedEntries) ?> Einträge</small>
        </div>

        <table class="table table-striped table-sm">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Formular</th>
                    <th>Name</th>
                    <th>E-Mail</th>
                    <th>Status</th>
                    <th>Erstellt</th>
                    <th>Gelöscht</th>
                    <th>Aktionen</th>
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
                                        onclick="return confirm('Eintrag #<?= $entry->id ?> wiederherstellen?')">
                                    ↩️ Wiederherstellen
                                </button>
                            </form>
                            <form method="post" action="hard_delete.php" style="display: inline;">
                                <input type="hidden" name="id" value="<?= $entry->id ?>">
                                <button type="submit" class="btn btn-sm btn-danger"
                                        onclick="return confirm('Eintrag #<?= $entry->id ?> PERMANENT löschen? Dies kann nicht rückgängig gemacht werden!')">
                                    🗑️ Permanent
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