<?php
// admin/inc/header.php
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <title>Admin · Anmeldungen</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <!-- Bootstrap 5 -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5/dist/css/bootstrap.min.css" rel="stylesheet">

    <!-- DataTables -->
    <link href="https://cdn.jsdelivr.net/npm/datatables.net-bs5/css/dataTables.bootstrap5.min.css" rel="stylesheet">

    <style>
        body {
            padding-top: 4.5rem;
        }
        .navbar-brand {
            font-weight: 600;
        }
        #anmeldungen_length, #anmeldungen_filter {
            margin-bottom: 1rem;
            width: 50%;
        }
        #anmeldungen_filter {
            text-align: right;
            float: right;
        }
        #anmeldungen_length {
            float: left;
        }
        #anmeldungen_filter input {
            margin-left: 0.5rem;‚
        }
        #anmeldungen_paginate span::after, #anmeldungen_paginate span::before, #anmeldungen_paginate span a::before  {
            content: "\2022";
            margin-right: 0.2rem;
            margin-left: 0.2rem;
        }
    </style>
</head>
<body>

<nav class="navbar navbar-expand-lg navbar-dark bg-dark fixed-top">
    <div class="container-fluid">
        <a class="navbar-brand" href="index.php">Anmeldungen</a>

        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
            <span class="navbar-toggler-icon"></span>
        </button>

        <div class="collapse navbar-collapse" id="navbarNav">
            <ul class="navbar-nav me-auto">
                <li class="nav-item">
                    <a class="nav-link" href="index.php">Übersicht</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="trash.php">Papierkorb</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="dashboard.php">Dashboard</a>
                </li>
            </ul>

            <div class="d-flex align-items-center gap-2">
                <?php
                $multiTenantHeaderEnabled = filter_var(
                    \App\Config\EnvLoader::get('MULTI_TENANT_ENABLED', 'false'),
                    FILTER_VALIDATE_BOOLEAN
                );
                if ($multiTenantHeaderEnabled && !empty($_SESSION['is_platform_admin'])):
                    $tenantRepo = new \App\Repositories\TenantRepository();
                    $allTenants = $tenantRepo->findAll();
                    $switchedId  = $_SESSION['switched_tenant_id'] ?? null;
                    $activeLabel = 'Alle Tenants';
                    if ($switchedId !== null) {
                        foreach ($allTenants as $t) {
                            if ((int)$t['id'] === (int)$switchedId) {
                                $activeLabel = htmlspecialchars($t['name']);
                                break;
                            }
                        }
                    }
                    $currentPage = basename($_SERVER['PHP_SELF'] ?? 'index.php');
                ?>
                    <div class="dropdown me-2">
                        <button class="btn btn-outline-light btn-sm dropdown-toggle" type="button"
                                data-bs-toggle="dropdown" aria-expanded="false">
                            <?= $activeLabel ?>
                        </button>
                        <ul class="dropdown-menu dropdown-menu-end">
                            <li>
                                <a class="dropdown-item<?= $switchedId === null ? ' active' : '' ?>"
                                   href="<?= htmlspecialchars($currentPage) ?>?switch_tenant=0">
                                    Alle Tenants
                                </a>
                            </li>
                            <?php if (!empty($allTenants)): ?>
                                <li><hr class="dropdown-divider"></li>
                                <?php foreach ($allTenants as $t): ?>
                                    <li>
                                        <a class="dropdown-item<?= ((int)($switchedId ?? -1) === (int)$t['id']) ? ' active' : '' ?>"
                                           href="<?= htmlspecialchars($currentPage) ?>?switch_tenant=<?= (int)$t['id'] ?>">
                                            <?= htmlspecialchars($t['name']) ?>
                                        </a>
                                    </li>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </ul>
                    </div>
                <?php endif; ?>

                <?php if (!empty($_SESSION['admin_logged_in'])): ?>
                    <span class="navbar-text text-light me-3">
                        <small>Angemeldet als: <?= htmlspecialchars($_SESSION['admin_username'] ?? 'Admin') ?></small>
                    </span>
                    <a href="logout.php" class="btn btn-outline-light btn-sm">
                        Abmelden
                    </a>
                <?php else: ?>
                    <span class="navbar-text text-muted">
                        Admin
                    </span>
                <?php endif; ?>
            </div>
        </div>
    </div>
</nav>
