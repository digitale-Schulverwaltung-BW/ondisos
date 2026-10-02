<?php
// Sections stored without title and text (e.g. empty rows of the admin form) render nothing.
if (trim((string)($section['title'] ?? '')) === '' && trim((string)($section['content'] ?? '')) === '') {
    return;
}
?>
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
