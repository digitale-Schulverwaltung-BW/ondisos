<?php
require __DIR__ . '/inc/auth.php';
require __DIR__ . '/inc/db.php';
require __DIR__ . '/inc/header.php';


$selectedForm = trim($_GET['form']) ?? '';
$allowedPerPage = [10, 25, 50, 100];

$perPage = (int)($_GET['perPage'] ?? 25);
if (!in_array($perPage, $allowedPerPage, true)) {
    $perPage = 25;
}
$page = max(1, (int)($_GET['page'] ?? 1));
$offset = ($page - 1) * $perPage;

$countSql = "SELECT COUNT(*) AS cnt FROM anmeldungen";
$countParams = [];
$countTypes = '';

if ($selectedForm !== '') {
    $countSql .= " WHERE formular = ?";
    $countParams[] = $selectedForm;
    $countTypes .= 's';
}

$countStmt = $mysqli->prepare($countSql);

if ($countParams) {
    $countStmt->bind_param($countTypes, ...$countParams);
}

$countStmt->execute();
$totalRows = $countStmt->get_result()->fetch_assoc()['cnt'];
$totalPages = max(1, (int)ceil($totalRows / $perPage));


$sql = "
    SELECT DISTINCT formular
    FROM anmeldungen
    ORDER BY formular ASC
";
$result = $mysqli->query($sql);

$forms = [];
while ($row = $result->fetch_assoc()) {
    $forms[] = $row['formular'];
}

$where = '';
$params = [];
$types  = '';
$sql = 'SELECT id, formular, formular_version, name, email, status, created_at';

if ($selectedForm !== '') {
    $sql = 'SELECT id, formular_version, name, email, status, created_at';
    $where = 'WHERE formular = ?';
    $params[] = $selectedForm;
    $types .= 's';
}

$sql .= "    
    FROM anmeldungen
    $where
    ORDER BY created_at DESC LIMIT ? OFFSET ?
";
$params[] = $perPage;
$params[] = $offset;
$types .= 'ii';

$stmt = $mysqli->prepare($sql);

if ($params) {
    $stmt->bind_param($types, ...$params);
}

$stmt->execute();
$result = $stmt->get_result();
?>

<div class="container mt-4">
    <h1>Anmeldungen</h1>
    <form method="get" class="mb-4">
        <div class="row g-2 align-items-center">
            <div class="col-auto">
                <label for="form" class="col-form-label">
                    Formular:
                </label>
            </div>

            <div class="col-auto">
                <select name="form" id="form" class="form-select"
                        onchange="this.form.submit()">
                    <option value="">Alle Formulare</option>

                    <?php foreach ($forms as $formKey): ?>
                        <option value="<?= htmlspecialchars($formKey) ?>"
                            <?= $formKey === $selectedForm ? 'selected' : '' ?>>
                            <?= htmlspecialchars($formKey) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
    </form>
    <form method="get" class="d-flex align-items-center gap-2 mb-3">

        <?php if (!empty($_GET['form'])): ?>
            <input type="hidden" name="form" value="<?= htmlspecialchars($_GET['form']) ?>">
        <?php endif; ?>

        <label for="perPage" class="form-label mb-0">
            Einträge pro Seite:
        </label>

        <select name="perPage"
                id="perPage"
                class="form-select form-select-sm w-auto"
                onchange="this.form.submit()">

            <?php foreach ($allowedPerPage as $n): ?>
                <option value="<?= $n ?>" <?= $n === $perPage ? 'selected' : '' ?>>
                    <?= $n ?>
                </option>
            <?php endforeach; ?>

        </select>

    </form>


    <table id="anmeldungen" class="table table-striped table-sm">
        <thead>
        <tr>
            <th>ID</th>
            <?php if (empty($selectedForm)): ?><th>Formular</th><?php endif; ?>
            <th>Version</th>
            <th>Name</th>
            <th>E-Mail</th>
            <th>Status</th>
            <th>Datum</th>
        </tr>
        </thead>
        <tbody>

        <?php while ($row = $result->fetch_assoc()): ?>
            <tr>
                <td><a href="detail.php?id=<?= (int)$row['id'] ?>">#<?= (int)$row['id'] ?></a></td>
                <?php if (empty($selectedForm)): ?><td><?= htmlspecialchars($row['formular']) ?></td><?php endif; ?>
                <td><?= htmlspecialchars($row['formular_version']) ?></td>
                <td><?= htmlspecialchars($row['name']) ?></td>
                <td><?= htmlspecialchars($row['email']) ?></td>
                <td><?= htmlspecialchars($row['status']) ?></td>
                <td><?= htmlspecialchars($row['created_at']) ?></td>
            </tr>
        <?php endwhile; ?>

        </tbody>
    </table>
    <nav>
        <ul class="pagination">
            <?php
            $baseParams = [
                'perPage' => $perPage
            ];
            if ($selectedForm !== '') {
                $baseParams['form'] = $selectedForm;
            }

            for ($i = 1; $i <= $totalPages; $i++):
                $baseParams['page'] = $i;
                $url = '?' . http_build_query($baseParams);
            ?>
                <li class="page-item <?= $i === $page ? 'active' : '' ?>">
                    <a class="page-link" href="<?= $url ?>">
                        <?= $i ?>
                    </a>
                </li>
            <?php endfor; ?>
        </ul>
    </nav>



    <a href="excel_export.php?<?= http_build_query($_GET) ?>"
   class="btn btn-success mb-3">
    📥 Excel-Export
    </a>

</div>
<?php require __DIR__ . '/inc/footer.php'; ?>
