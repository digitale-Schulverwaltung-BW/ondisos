<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <title><?= htmlspecialchars($config['title'] ?? 'Anmeldebestätigung') ?></title>
    <style><?= $styles ?></style>
</head>
<body>
    <?php include __DIR__ . '/sections/header.php'; ?>

    <div class="content">
        <h1><?= htmlspecialchars($config['title'] ?? 'Anmeldebestätigung') ?></h1>

        <?php if (!empty($config['intro_text'])): ?>
            <p class="intro"><?= nl2br(htmlspecialchars($config['intro_text'])) ?></p>
        <?php endif; ?>

        <table class="meta-table">
            <tr>
                <th>Referenznummer:</th>
                <td><strong>#<?= $anmeldung->id ?></strong></td>
            </tr>
            <tr>
                <th>Formular:</th>
                <td><?= htmlspecialchars($anmeldung->formular) ?></td>
            </tr>
            <tr>
                <th>Datum:</th>
                <td><?= $anmeldung->createdAt->format('d.m.Y H:i') ?> Uhr</td>
            </tr>
            <tr>
                <th>Status:</th>
                <td><?= htmlspecialchars($anmeldung->status) ?></td>
            </tr>
        </table>

        <?php if (!empty($config['pre_sections'])): ?>
            <?php foreach ($config['pre_sections'] as $section): ?>
                <?php include __DIR__ . '/sections/custom-section.php'; ?>
            <?php endforeach; ?>
        <?php endif; ?>

        <?php include __DIR__ . '/sections/data-table.php'; ?>

        <?php if (!empty($config['post_sections'])): ?>
            <?php foreach ($config['post_sections'] as $section): ?>
                <?php include __DIR__ . '/sections/custom-section.php'; ?>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <?php include __DIR__ . '/sections/footer.php'; ?>
</body>
</html>
