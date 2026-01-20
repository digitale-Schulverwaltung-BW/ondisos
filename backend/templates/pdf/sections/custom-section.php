<div class="custom-section">
    <?php if (!empty($section['title'])): ?>
        <h3><?= htmlspecialchars($section['title']) ?></h3>
    <?php endif; ?>

    <?php if (!empty($section['content'])): ?>
        <div class="section-content">
            <?= nl2br(htmlspecialchars($section['content'])) ?>
        </div>
    <?php endif; ?>
</div>
