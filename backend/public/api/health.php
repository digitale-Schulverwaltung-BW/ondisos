<?php
// backend/public/api/health.php

declare(strict_types=1);

define('SKIP_AUTH_CHECK', true);
define('SKIP_AUTO_EXPUNGE', true); // Kein DB-Connect nötig → extrem schnell

require_once __DIR__ . '/../../inc/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

// CORS – identisch zu submit.php
$allowedOrigins = getenv('ALLOWED_ORIGINS')
    ? array_map('trim', explode(',', (string)getenv('ALLOWED_ORIGINS')))
    : [];

$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if ($origin !== '' && in_array($origin, $allowedOrigins, true)) {
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Access-Control-Allow-Methods: GET, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type');
    header('Vary: Origin');
}

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

http_response_code(200);
echo json_encode(['status' => 'ok', 'timestamp' => time()]);
