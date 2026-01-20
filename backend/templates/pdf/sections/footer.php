<div class="footer">
    <?php if (!empty($config['footer_text'])): ?>
        <p class="footer-text"><?= nl2br(htmlspecialchars($config['footer_text'])) ?></p>
    <?php endif; ?>

    <p class="footer-meta">
        Erstellt am <?= date('d.m.Y') ?> um <?= date('H:i') ?> Uhr
    </p>
</div>
