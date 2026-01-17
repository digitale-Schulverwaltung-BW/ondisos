<?php
// public/detail.php

declare(strict_types=1);

require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/auth.php';

use App\Controllers\DetailController;
use App\Repositories\AnmeldungRepository;
use App\Utils\NullableHelpers as NH;

// Initialize dependencies
$repository = new AnmeldungRepository();
$controller = new DetailController($repository);

// Handle request with error handling
try {
    $id = (int)($_GET['id'] ?? 0);
    $viewData = $controller->show($id);
    extract($viewData);
} catch (InvalidArgumentException $e) {
    http_response_code(404);
    require __DIR__ . '/../inc/header.php';
    ?>
    <div class="container mt-4">
        <div class="alert alert-danger">
            <h4>Fehler</h4>
            <p><?= htmlspecialchars($e->getMessage()) ?></p>
        </div>
        <a href="index.php" class="btn btn-secondary">← Zurück zur Übersicht</a>
    </div>
    <?php
    require __DIR__ . '/../inc/footer.php';
    exit;
}

require __DIR__ . '/../inc/header.php';
?>

<div class="container mt-4">
    <!-- Header -->
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1>Anmeldung #<?= $anmeldung->id ?></h1>
        <a href="index.php?form=<?= urlencode($anmeldung->formular) ?>" 
           class="btn btn-secondary">
            ← Zurück zur Übersicht
        </a>
    </div>

    <!-- Meta Information Card -->
    <div class="card mb-4">
        <div class="card-header">
            <h5 class="mb-0">Übersicht</h5>
        </div>
        <div class="card-body">
            <div class="row">
                <div class="col-md-6">
                    <dl class="row mb-0">
                        <dt class="col-sm-4">Formular:</dt>
                        <dd class="col-sm-8"><?= NH::displayHtml($anmeldung->formular) ?></dd>

                        <dt class="col-sm-4">Version:</dt>
                        <dd class="col-sm-8"><?= NH::displayHtml($anmeldung->formularVersion, 'v1.0') ?></dd>

                        <dt class="col-sm-4">Status:</dt>
                        <dd class="col-sm-8">
                            <span class="badge bg-primary">
                                <?= NH::displayHtml($anmeldung->status) ?>
                            </span>
                        </dd>
                    </dl>
                </div>
                <div class="col-md-6">
                    <dl class="row mb-0">
                        <dt class="col-sm-4">Erstellt:</dt>
                        <dd class="col-sm-8"><?= $anmeldung->createdAt->format('d.m.Y H:i:s') ?></dd>

                        <?php if ($anmeldung->updatedAt): ?>
                            <dt class="col-sm-4">Aktualisiert:</dt>
                            <dd class="col-sm-8"><?= $anmeldung->updatedAt->format('d.m.Y H:i:s') ?></dd>
                        <?php endif; ?>

                        <?php if ($anmeldung->deleted): ?>
                            <dt class="col-sm-4">Gelöscht:</dt>
                            <dd class="col-sm-8 text-danger">
                                <?= $anmeldung->deletedAt?->format('d.m.Y H:i:s') ?? 'Ja' ?>
                            </dd>
                        <?php endif; ?>
                    </dl>
                </div>
            </div>
        </div>
    </div>

    <!-- Data Card -->
    <div class="card mb-4">
        <div class="card-header">
            <h5 class="mb-0">Formulardaten</h5>
        </div>
        <div class="card-body">
            <?php if (empty($structuredData)): ?>
                <p class="text-muted">Keine Daten vorhanden</p>
            <?php else: ?>
                <table class="table table-bordered table-sm">
                    <tbody>
                    <?php foreach ($structuredData as $field): ?>
                        <tr>
                            <th style="width: 30%">
                                <?= htmlspecialchars($field['label']) ?>
                                <small class="text-muted d-block"><?= htmlspecialchars($field['key']) ?></small>
                            </th>
                            <td>
                                <?php 
                                $value = $field['value'];
                                $type = $field['type'];
                                
                                switch ($type) {
                                    case 'array':
                                        echo '<pre class="mb-0 small">' 
                                           . htmlspecialchars(json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) 
                                           . '</pre>';
                                        break;
                                    
                                    case 'boolean':
                                        echo $value 
                                            ? '<span class="badge bg-success">Ja</span>' 
                                            : '<span class="badge bg-secondary">Nein</span>';
                                        break;
                                    
                                    case 'url':
                                        echo '<a href="' . htmlspecialchars($value) . '" target="_blank" rel="noopener">' 
                                           . htmlspecialchars($value) 
                                           . ' <i class="bi bi-box-arrow-up-right"></i></a>';
                                        break;
                                    
                                    case 'email':
                                        echo '<a href="mailto:' . htmlspecialchars($value) . '">' 
                                           . htmlspecialchars($value) 
                                           . '</a>';
                                        break;
                                    
                                    case 'date':
                                        $date = new DateTimeImmutable($value);
                                        echo htmlspecialchars($date->format('d.m.Y'));
                                        break;
                                    
                                    default:
                                        if ($field['isFile'] && !empty($value)) {
                                            echo '<code>' . htmlspecialchars($value) . '</code>';
                                            echo ' <span class="badge bg-info">Datei</span>';
                                        } else {
                                            echo nl2br(htmlspecialchars((string)$value));
                                        }
                                }
                                ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>

    <!-- Uploaded Files Card -->
    <?php if (!empty($uploadedFiles)): ?>
        <div class="card mb-4">
            <div class="card-header">
                <h5 class="mb-0">Hochgeladene Dateien</h5>
            </div>
            <div class="card-body">
                <div class="list-group">
                    <?php foreach ($uploadedFiles as $file): ?>
                        <a href="<?= htmlspecialchars($file['downloadUrl']) ?>" 
                           class="list-group-item list-group-item-action d-flex justify-content-between align-items-center">
                            <div>
                                <i class="bi bi-file-earmark"></i>
                                <?= htmlspecialchars($file['name']) ?>
                                <small class="text-muted">(<?= htmlspecialchars($file['sizeFormatted']) ?>)</small>
                            </div>
                            <span class="badge bg-primary">Download</span>
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <!-- Actions -->
    <div class="d-flex gap-2 mb-4">
        <a href="index.php?form=<?= urlencode($anmeldung->formular) ?>"
           class="btn btn-secondary">
            ← Zurück
        </a>

        <a href="excel_export.php?id=<?= $anmeldung->id ?>"
           class="btn btn-success">
            Excel herunterladen
        </a>

        <?php if (!$anmeldung->deleted): ?>
            <button class="btn btn-warning" onclick="alert('Status ändern - TODO')">
                Status ändern
            </button>
            <button class="btn btn-danger" onclick="if(confirm('Wirklich löschen?')) alert('Löschen - TODO')">
                Löschen
            </button>
        <?php endif; ?>
    </div>
</div>

<?php require __DIR__ . '/../inc/footer.php'; ?>