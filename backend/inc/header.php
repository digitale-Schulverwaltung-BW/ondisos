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
        <a class="navbar-brand" href="/admin/">Anmeldungen</a>

        <span class="navbar-text text-muted ms-3">
            Admin
        </span>
    </div>
</nav>
