<div class="header">
    <?php if (!empty($logoBase64)): ?>
        <div class="logo-container">
            <?php if (!empty($logoData['width']) && !empty($logoData['height'])): ?>
                <img src="<?= $logoBase64 ?>" alt="Logo" class="logo" width="<?= $logoData['width'] ?>" height="<?= $logoData['height'] ?>">
            <?php else: ?>
                <img src="<?= $logoBase64 ?>" alt="Logo" class="logo">
            <?php endif; ?>
        </div>
    <?php endif; ?>
    <div class="header-title"><?= htmlspecialchars($config['header_title'] ?? 'Anmeldebestätigung') ?></div>
</div>
