<?php
// admin/inc/db.php

require_once __DIR__ . '/config.php';

$mysqli = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);

if ($mysqli->connect_errno) {
    error_log('DB-Connect fehlgeschlagen: ' . $mysqli->connect_error);
    http_response_code(500);
    exit('Datenbank nicht erreichbar');
}

$mysqli->set_charset('utf8mb4');
