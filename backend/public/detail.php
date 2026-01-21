<?php
// public/detail.php

declare(strict_types=1);

require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/auth.php';

use App\Controllers\DetailController;
use App\Repositories\AnmeldungRepository;
use App\Utils\NullableHelpers as NH;
use App\Services\MessageService as M;

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
            <h4><?= M::get('errors.generic_error') ?></h4>
            <p><?= htmlspecialchars($e->getMessage()) ?></p>
        </div>
        <a href="index.php" class="btn btn-secondary"><?= M::get('ui.back_to_overview') ?></a>
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
        <h1><?= M::get('ui.detail.title') ?> #<?= $anmeldung->id ?></h1>
        <a href="index.php?form=<?= urlencode($anmeldung->formular) ?>"
           class="btn btn-secondary">
            <?= M::get('ui.back_to_overview') ?>
        </a>
    </div>

    <!-- Meta Information Card -->
    <div class="card mb-4">
        <div class="card-header">
            <h5 class="mb-0"><?= M::get('ui.detail.basic_info') ?></h5>
        </div>
        <div class="card-body">
            <div class="row">
                <div class="col-md-6">
                    <dl class="row mb-0">
                        <dt class="col-sm-4"><?= M::get('ui.table.form') ?>:</dt>
                        <dd class="col-sm-8"><?= NH::displayHtml($anmeldung->formular) ?></dd>

                        <dt class="col-sm-4">Version:</dt>
                        <dd class="col-sm-8"><?= NH::displayHtml($anmeldung->formularVersion, 'v1.0') ?></dd>

                        <dt class="col-sm-4"><?= M::get('ui.table.status') ?>:</dt>
                        <dd class="col-sm-8">
                            <span class="badge bg-primary">
                                <?= NH::displayHtml($anmeldung->status) ?>
                            </span>
                        </dd>
                    </dl>
                </div>
                <div class="col-md-6">
                    <dl class="row mb-0">
                        <dt class="col-sm-4"><?= M::get('ui.table.created_at') ?>:</dt>
                        <dd class="col-sm-8"><?= $anmeldung->createdAt->format('d.m.Y H:i:s') ?></dd>

                        <?php if ($anmeldung->updatedAt): ?>
                            <dt class="col-sm-4"><?= M::get('ui.table.updated_at') ?>:</dt>
                            <dd class="col-sm-8"><?= $anmeldung->updatedAt->format('d.m.Y H:i:s') ?></dd>
                        <?php endif; ?>

                        <?php if ($anmeldung->deleted): ?>
                            <dt class="col-sm-4"><?= M::get('ui.table.deleted_at') ?>:</dt>
                            <dd class="col-sm-8 text-danger">
                                <?= $anmeldung->deletedAt?->format('d.m.Y H:i:s') ?? M::get('ui.detail.deleted_yes', 'Ja') ?>
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
            <h5 class="mb-0"><?= M::get('ui.detail.form_data') ?></h5>
        </div>
        <div class="card-body">
            <?php if (empty($structuredData)): ?>
                <p class="text-muted"><?= M::get('ui.detail.no_data', 'Keine Daten vorhanden') ?></p>
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
                                        // Check if this is a SurveyJS file upload with base64 content
                                        $isBase64Files = is_array($value)
                                            && !empty($value)
                                            && isset($value[0]['content'])
                                            && is_string($value[0]['content'])
                                            && str_starts_with($value[0]['content'], 'data:');

                                        if ($isBase64Files) {
                                            // Render base64 images/files
                                            foreach ($value as $file) {
                                                $content = $file['content'] ?? '';
                                                $name = $file['name'] ?? 'unnamed';
                                                $type = $file['type'] ?? '';

                                                // Check if it's an image
                                                if (str_starts_with($content, 'data:image/')) {
                                                    echo '<div class="mb-2">';
                                                    echo '<img src="' . htmlspecialchars($content) . '" alt="' . htmlspecialchars($name) . '" class="img-fluid" style="max-width: 100%; max-height: 400px;">';
                                                    echo '<br><small class="text-muted">' . htmlspecialchars($name) . '</small>';
                                                    echo '<br><a href="' . htmlspecialchars($content) . '" download="' . htmlspecialchars($name) . '" class="btn btn-sm btn-outline-primary mt-1">';
                                                    echo '<i class="bi bi-download"></i> ' . M::get('ui.buttons.download');
                                                    echo '</a>';
                                                    echo '</div>';
                                                } else {
                                                    // For other file types (PDFs, etc.), show download link
                                                    echo '<div class="mb-2">';
                                                    echo '<a href="' . htmlspecialchars($content) . '" download="' . htmlspecialchars($name) . '" class="btn btn-sm btn-outline-primary">';
                                                    echo '<i class="bi bi-download"></i> ' . htmlspecialchars($name);
                                                    echo '</a>';
                                                    echo '</div>';
                                                }
                                            }
                                        } else {
                                            // Default: show as formatted JSON
                                            echo '<pre class="mb-0 small">'
                                               . htmlspecialchars(json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE))
                                               . '</pre>';
                                        }
                                        break;

                                    case 'boolean':
                                        echo $value
                                            ? '<span class="badge bg-success">' . M::get('ui.detail.yes', 'Ja') . '</span>'
                                            : '<span class="badge bg-secondary">' . M::get('ui.detail.no', 'Nein') . '</span>';
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
                                            $fileUrl = 'download.php?file=' . urlencode((string)$value) . '&mode=view';
                                            echo '<a href="' . htmlspecialchars($fileUrl) . '" target="_blank" rel="noopener noreferrer">';
                                            echo '<code>' . htmlspecialchars($value) . '</code>';
                                            echo '</a>';
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
                <h5 class="mb-0"><?= M::get('ui.detail.file_uploads') ?></h5>
            </div>
            <div class="card-body">
                <div class="list-group">
                    <?php foreach ($uploadedFiles as $file): ?>
                        <a href="<?= htmlspecialchars($file['downloadUrl']) ?>"
                           target="_blank"
                           rel="noopener noreferrer"
                           class="list-group-item list-group-item-action d-flex justify-content-between align-items-center">
                            <div>
                                <i class="bi bi-file-earmark"></i>
                                <?= htmlspecialchars($file['name']) ?>
                                <small class="text-muted">(<?= htmlspecialchars($file['sizeFormatted']) ?>)</small>
                            </div>
                            <span class="badge bg-primary"><?= M::get('ui.buttons.view') ?></span>
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
            <?= M::get('ui.back_to_overview') ?>
        </a>

        <a href="excel_export.php?id=<?= $anmeldung->id ?>"
           class="btn btn-success">
            <?= M::get('ui.buttons.excel_export') ?>
        </a>

        <?php if (!$anmeldung->deleted): ?>
            <button class="btn btn-warning" onclick="alert('<?= M::get('ui.detail.mark_as') ?> - TODO')">
                <?= M::get('ui.detail.mark_as') ?>
            </button>
            <button class="btn btn-danger" onclick="if(confirm('<?= M::get('ui.trash.confirm_hard_delete', 'Wirklich löschen?') ?>')) alert('<?= M::get('ui.buttons.delete') ?> - TODO')">
                <?= M::get('ui.buttons.delete') ?>
            </button>
        <?php endif; ?>
    </div>
</div>

<?php require __DIR__ . '/../inc/footer.php'; ?>