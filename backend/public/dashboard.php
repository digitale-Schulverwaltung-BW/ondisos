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
use App\Services\MessageService as M;

// Initialize services
$repository = new AnmeldungRepository();
$statusService = new StatusService($repository);
$expungeService = new ExpungeService($repository);
$requestExpungeService = new RequestExpungeService($expungeService);

// Handle manual expunge trigger
if (isset($_POST['force_expunge'])) {
    try {
        $result = $requestExpungeService->forceRun();
        $successMessage = M::format('success.expunge_completed', ['count' => $result['deleted']]);
    } catch (Throwable $e) {
        $errorMessage = M::withContact('errors.expunge_failed') . ': ' . $e->getMessage();
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
    <h1><?= M::get('ui.dashboard') ?></h1>

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
            'neu' => ['class' => 'primary', 'icon' => '📬'],
            'exportiert' => ['class' => 'info', 'icon' => '📤'],
            'in_bearbeitung' => ['class' => 'warning', 'icon' => '⚙️'],
            'akzeptiert' => ['class' => 'success', 'icon' => '✅'],
            'abgelehnt' => ['class' => 'danger', 'icon' => '❌'],
            'archiviert' => ['class' => 'secondary', 'icon' => '📦']
        ];

        foreach ($statusLabels as $status => $info):
            $count = $stats[$status] ?? 0;
        ?>
            <div class="col-md-4 col-lg-2 mb-3">
                <div class="card text-center">
                    <div class="card-body">
                        <div class="fs-2 mb-2"><?= $info['icon'] ?></div>
                        <h3 class="card-title display-6"><?= $count ?></h3>
                        <p class="card-text text-muted"><?= M::get('status.' . $status) ?></p>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

    <!-- Auto-Expunge Status -->
    <div class="card mb-4">
        <div class="card-header">
            <h5 class="mb-0"><?= M::get('ui.dashboard.auto_expunge_status') ?></h5>
        </div>
        <div class="card-body">
            <div class="row">
                <div class="col-md-6">
                    <dl class="row">
                        <dt class="col-sm-5"><?= M::get('ui.table.status') ?>:</dt>
                        <dd class="col-sm-7">
                            <?php if ($config->autoExpungeDays > 0): ?>
                                <span class="badge bg-success"><?= M::format('expunge.enabled', ['days' => $config->autoExpungeDays]) ?></span>
                            <?php else: ?>
                                <span class="badge bg-secondary"><?= M::get('expunge.disabled') ?></span>
                            <?php endif; ?>
                        </dd>

                        <dt class="col-sm-5"><?= M::get('ui.dashboard.expunge_days', 'Löschfrist') ?>:</dt>
                        <dd class="col-sm-7">
                            <?php if ($config->autoExpungeDays > 0): ?>
                                <?= M::format('ui.dashboard.days_count', ['days' => $config->autoExpungeDays]) ?>
                            <?php else: ?>
                                -
                            <?php endif; ?>
                        </dd>

                        <dt class="col-sm-5"><?= M::get('ui.dashboard.entries_ready') ?>:</dt>
                        <dd class="col-sm-7">
                            <?php if ($expungePreview['count'] > 0): ?>
                                <span class="badge bg-warning text-dark">
                                    <?= M::format('expunge.entries_ready', ['count' => $expungePreview['count']]) ?>
                                </span>
                            <?php else: ?>
                                <span class="badge bg-success"><?= M::get('expunge.no_entries_ready') ?></span>
                            <?php endif; ?>
                        </dd>
                    </dl>
                </div>

                <div class="col-md-6">
                    <dl class="row">
                        <dt class="col-sm-5"><?= M::get('ui.dashboard.last_expunge') ?>:</dt>
                        <dd class="col-sm-7">
                            <?php if ($expungeInfo['lastRun']): ?>
                                <?= $expungeInfo['lastRun']->format('d.m.Y H:i:s') ?>
                            <?php else: ?>
                                <em><?= M::get('ui.dashboard.never_run', 'Noch nie ausgeführt') ?></em>
                            <?php endif; ?>
                        </dd>

                        <dt class="col-sm-5"><?= M::get('ui.dashboard.next_expunge') ?>:</dt>
                        <dd class="col-sm-7">
                            <?php if ($expungeInfo['nextRun']): ?>
                                <?= $expungeInfo['nextRun']->format('d.m.Y H:i:s') ?>
                                <?php if ($expungeInfo['canRunNow']): ?>
                                    <small class="text-muted"><?= M::get('ui.dashboard.overdue', '(überfällig)') ?></small>
                                <?php endif; ?>
                            <?php else: ?>
                                <em><?= M::get('ui.dashboard.next_page_load', 'Bei nächstem Seitenaufruf') ?></em>
                            <?php endif; ?>
                        </dd>

                        <dt class="col-sm-5"><?= M::get('ui.dashboard.oldest_entry', 'Ältester Eintrag') ?>:</dt>
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
                    <strong><?= M::get('ui.info') ?></strong> <?= M::get('ui.dashboard.expunge_auto_info', 'Auto-Expunge läuft automatisch alle 6 Stunden bei einem Seitenaufruf.') ?>
                    <?= M::get('ui.dashboard.next_check', 'Die nächste automatische Prüfung erfolgt') ?>
                    <?php if ($expungeInfo['nextRun']): ?>
                        <?= M::get('ui.dashboard.on', 'am') ?> <strong><?= $expungeInfo['nextRun']->format('d.m.Y') ?></strong>
                        <?= M::get('ui.dashboard.at', 'um') ?> <strong><?= $expungeInfo['nextRun']->format('H:i') ?> <?= M::get('ui.dashboard.oclock', 'Uhr') ?></strong>.
                    <?php else: ?>
                        <?= M::get('ui.dashboard.next_page_load', 'beim nächsten Seitenaufruf') ?>.
                    <?php endif; ?>
                </div>

                <form method="post" class="mt-3">
                    <button type="submit" name="force_expunge" class="btn btn-danger"
                            onclick="return confirm('<?= M::format('ui.dashboard.confirm_expunge', ['count' => $expungePreview['count']]) ?>')">
                        <?= M::get('ui.dashboard.run_expunge_now', '🗑️ Jetzt manuell ausführen') ?>
                    </button>
                </form>
            <?php endif; ?>
        </div>
    </div>

    <!-- Quick Actions -->
    <div class="card">
        <div class="card-header">
            <h5 class="mb-0"><?= M::get('ui.dashboard.quick_actions', '⚡ Schnellzugriff') ?></h5>
        </div>
        <div class="card-body">
            <div class="d-flex gap-2">
                <a href="index.php?status=neu" class="btn btn-primary">
                    <?= M::get('ui.dashboard.new_anmeldungen') ?>
                    <?php if (($stats['neu'] ?? 0) > 0): ?>
                        <span class="badge bg-light text-dark"><?= $stats['neu'] ?></span>
                    <?php endif; ?>
                </a>
                <a href="index.php" class="btn btn-secondary">
                    <?= M::get('ui.dashboard.all_anmeldungen', '📋 Alle Anmeldungen') ?>
                </a>
                <a href="excel_export.php" class="btn btn-success">
                    <?= M::get('ui.buttons.excel_export') ?>
                </a>
            </div>
        </div>
    </div>
</div>

<?php require __DIR__ . '/../inc/footer.php'; ?>