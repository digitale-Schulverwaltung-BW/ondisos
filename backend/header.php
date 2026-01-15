<h1>Anmeldung #<?= $submission['id'] ?></h1>

<div class="mb-4 text-muted">
    Formular: <strong><?= htmlspecialchars($submission['formular']) ?></strong><br>
    Eingegangen am: <?= htmlspecialchars($submission['created_at']) ?>
</div>
