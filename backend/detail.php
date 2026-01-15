<?php

require __DIR__ . '/inc/auth.php';
require __DIR__ . '/inc/db.php';

$id = (int)($_GET['id'] ?? 0);

if ($id <= 0) {
    die('Ungültige ID');
}

$sql = "
    SELECT id, formular, data, created_at
    FROM anmeldungen
    WHERE id = ?
";
$stmt = $mysqli->prepare($sql);
$stmt->bind_param('i', $id);
$stmt->execute();
$submission = $stmt->get_result()->fetch_assoc();

if (!$submission) {
    die('Eintrag nicht gefunden');
}

$data = json_decode($submission['data'], true);
require 'header.php';
?>
<table class="table table-bordered table-sm">
    <tbody>
    <?php foreach ($data as $key => $value): ?>
        <tr>
            <th style="width: 30%">
                <?= htmlspecialchars($key) ?>
            </th>
            <td>
                <?php
                if (is_array($value)) {
                    echo '<pre class="mb-0">' . htmlspecialchars(json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) . '</pre>';
                } else {
                    echo htmlspecialchars((string)$value);
                }
                ?>
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
<?php if (is_file("uploads/$value")) {
    echo "<p><a href='download.php?id=...'>Download</a></p>";
}
?>

<a href="index.php?<?= http_build_query($_GET) ?>"
   class="btn btn-secondary">
    ← Zurück zur Übersicht
</a>

