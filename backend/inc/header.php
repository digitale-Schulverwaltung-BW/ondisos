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

            <div class="d-flex align-items-center">
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
