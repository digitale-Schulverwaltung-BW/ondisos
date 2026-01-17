<?php
// public/dashboard.php (optional)

declare(strict_types=1);

require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/auth.php';

use App\Repositories\AnmeldungRepository;
use App\Services\ExpungeService;
use App\Services\RequestExpungeService;
use App\Services\StatusService;
use App\Config\Config;

// Initialize services
$repository = new AnmeldungRepository();
$statusService = new StatusService($repository);
$expungeService = new ExpungeService($repository);
$requestExpungeService = new RequestExpungeService($expungeService);

// Handle manual expunge trigger
if (isset($_POST['force_expunge'])) {
    try {
        $result = $requestExpungeService->forceRun();
        $successMessage = "Expunge erfolgreich durchgeführt. {$result['deleted']} Einträge gelöscht.";
    } catch (Throwable $e) {
        $errorMessage = "Expunge fehlgeschlagen: " . $e->getMessage();
    }
}

// Get statistics
$stats = $statusService->getStatistics();
$config = Config::getInstance();
$expungeInfo = $requestExpungeService->getLastRunInfo();
$expungePreview = $expungeService->previewExpunge();

require __DIR__ . '/../inc/header.php';
?>

<div class="container mt-4">
    <h1>Dashboard</h1>

    <?php if (isset($successMessage)): ?>
        <div class="alert alert-success alert-dismissible fade show">
            <?= htmlspecialchars($successMessage) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <?php if (isset($errorMessage)): ?>
        <div class="alert alert-danger alert-dismissible fade show">
            <?= htmlspecialchars($errorMessage) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <!-- Statistics Cards -->
    <div class="row mb-4">
        <?php
        $statusLabels = [
            'neu' => ['label' => 'Neue', 'class' => 'primary', 'icon' => '📬'],
            'exportiert' => ['label' => 'Exportiert', 'class' => 'info', 'icon' => '📤'],
            'in_bearbeitung' => ['label' => 'In Bearbeitung', 'class' => 'warning', 'icon' => '⚙️'],
            'akzeptiert' => ['label' => 'Akzeptiert', 'class' => 'success', 'icon' => '✅'],
            'abgelehnt' => ['label' => 'Abgelehnt', 'class' => 'danger', 'icon' => '❌'],
            'archiviert' => ['label' => 'Archiviert', 'class' => 'secondary', 'icon' => '📦']
        ];

        foreach ($statusLabels as $status => $info):
            $count = $stats[$status] ?? 0;
        ?>
            <div class="col-md-4 col-lg-2 mb-3">
                <div class="card text-center">
                    <div class="card-body">
                        <div class="fs-2 mb-2"><?= $info['icon'] ?></div>
                        <h3 class="card-title display-6"><?= $count ?></h3>
                        <p class="card-text text-muted"><?= $info['label'] ?></p>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

    <!-- Auto-Expunge Status -->
    <div class="card mb-4">
        <div class="card-header">
            <h5 class="mb-0">🗑️ Auto-Expunge Status</h5>
        </div>
        <div class="card-body">
            <div class="row">
                <div class="col-md-6">
                    <dl class="row">
                        <dt class="col-sm-5">Status:</dt>
                        <dd class="col-sm-7">
                            <?php if ($config->autoExpungeDays > 0): ?>
                                <span class="badge bg-success">Aktiviert</span>
                            <?php else: ?>
                                <span class="badge bg-secondary">Deaktiviert</span>
                            <?php endif; ?>
                        </dd>

                        <dt class="col-sm-5">Löschfrist:</dt>
                        <dd class="col-sm-7">
                            <?php if ($config->autoExpungeDays > 0): ?>
                                <?= $config->autoExpungeDays ?> Tage
                            <?php else: ?>
                                -
                            <?php endif; ?>
                        </dd>

                        <dt class="col-sm-5">Bereit zum Löschen:</dt>
                        <dd class="col-sm-7">
                            <?php if ($expungePreview['count'] > 0): ?>
                                <span class="badge bg-warning text-dark">
                                    <?= $expungePreview['count'] ?> Einträge
                                </span>
                            <?php else: ?>
                                <span class="badge bg-success">0 Einträge</span>
                            <?php endif; ?>
                        </dd>
                    </dl>
                </div>

                <div class="col-md-6">
                    <dl class="row">
                        <dt class="col-sm-5">Letzter Lauf:</dt>
                        <dd class="col-sm-7">
                            <?php if ($expungeInfo['lastRun']): ?>
                                <?= $expungeInfo['lastRun']->format('d.m.Y H:i:s') ?>
                            <?php else: ?>
                                <em>Noch nie ausgeführt</em>
                            <?php endif; ?>
                        </dd>

                        <dt class="col-sm-5">Nächster Lauf:</dt>
                        <dd class="col-sm-7">
                            <?php if ($expungeInfo['nextRun']): ?>
                                <?= $expungeInfo['nextRun']->format('d.m.Y H:i:s') ?>
                                <?php if ($expungeInfo['canRunNow']): ?>
                                    <small class="text-muted">(überfällig)</small>
                                <?php endif; ?>
                            <?php else: ?>
                                <em>Bei nächstem Seitenaufruf</em>
                            <?php endif; ?>
                        </dd>

                        <dt class="col-sm-5">Ältester Eintrag:</dt>
                        <dd class="col-sm-7">
                            <?php if ($expungePreview['oldest']): ?>
                                <?= $expungePreview['oldest']->format('d.m.Y') ?>
                            <?php else: ?>
                                -
                            <?php endif; ?>
                        </dd>
                    </dl>
                </div>
            </div>

            <?php if ($config->autoExpungeDays > 0 && $expungePreview['count'] > 0): ?>
                <div class="alert alert-info mt-3 mb-0">
                    <strong>ℹ️ Hinweis:</strong> Auto-Expunge läuft automatisch alle 6 Stunden bei einem Seitenaufruf.
                    Die nächste automatische Prüfung erfolgt 
                    <?php if ($expungeInfo['nextRun']): ?>
                        am <strong><?= $expungeInfo['nextRun']->format('d.m.Y') ?></strong> 
                        um <strong><?= $expungeInfo['nextRun']->format('H:i') ?> Uhr</strong>.
                    <?php else: ?>
                        beim nächsten Seitenaufruf.
                    <?php endif; ?>
                </div>

                <form method="post" class="mt-3">
                    <button type="submit" name="force_expunge" class="btn btn-danger"
                            onclick="return confirm('<?= $expungePreview['count'] ?> Einträge werden permanent gelöscht. Fortfahren?')">
                        🗑️ Jetzt manuell ausführen
                    </button>
                </form>
            <?php endif; ?>
        </div>
    </div>

    <!-- Quick Actions -->
    <div class="card">
        <div class="card-header">
            <h5 class="mb-0">⚡ Schnellzugriff</h5>
        </div>
        <div class="card-body">
            <div class="d-flex gap-2">
                <a href="index.php?status=neu" class="btn btn-primary">
                    📬 Neue Anmeldungen
                    <?php if (($stats['neu'] ?? 0) > 0): ?>
                        <span class="badge bg-light text-dark"><?= $stats['neu'] ?></span>
                    <?php endif; ?>
                </a>
                <a href="index.php" class="btn btn-secondary">
                    📋 Alle Anmeldungen
                </a>
                <a href="excel_export.php" class="btn btn-success">
                    📥 Excel-Export
                </a>
            </div>
        </div>
    </div>
</div>

<?php require __DIR__ . '/../inc/footer.php'; ?>