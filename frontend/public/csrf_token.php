<?php
// frontend/public/csrf_token.php
// Optional endpoint for AJAX token refresh

declare(strict_types=1);

//require_once __DIR__ . '/../../../vendor/autoload.php';
require_once __DIR__ . '/../inc/bootstrap.php';

use Frontend\Utils\CsrfProtection;

header('Content-Type: application/json; charset=UTF-8');

// Get or generate token
$token = CsrfProtection::getToken();

echo json_encode(['token' => $token]);