<?php
// backend/public/api/submit.php

declare(strict_types=1);

require_once __DIR__ . '/../../inc/bootstrap.php';

use App\Repositories\AnmeldungRepository;
use App\Validators\AnmeldungValidator;

header('Content-Type: application/json; charset=utf-8');

// Allow CORS from frontend (configure as needed)
$allowedOrigins = getenv('ALLOWED_ORIGINS') 
    ? explode(',', getenv('ALLOWED_ORIGINS'))
    : ['http://localhost'];

$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if (in_array($origin, $allowedOrigins, true)) {
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Access-Control-Allow-Methods: POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type');
}

// Handle preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

try {
    // Validate request method
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new RuntimeException('Invalid request method', 405);
    }

    // Get JSON payload
    $input = file_get_contents('php://input');
    $payload = json_decode($input, true, 512, JSON_THROW_ON_ERROR);

    if (!is_array($payload)) {
        throw new RuntimeException('Invalid JSON payload', 400);
    }

    // Extract data
    $formKey = $payload['form_key'] ?? '';
    $data = $payload['data'] ?? [];
    $metadata = $payload['metadata'] ?? [];

    if (empty($formKey)) {
        throw new RuntimeException('Missing form_key', 400);
    }

    if (empty($data) || !is_array($data)) {
        throw new RuntimeException('Invalid or missing data', 400);
    }

    // Extract common fields
    $name = $payload['name'] ?? $data['Name'] ?? $data['name'] ?? null;
    $email = $payload['email'] ?? $data['email'] ?? $data['email1'] ?? $data['Email'] ?? $data['E-mail'] ?? $data['E-Mail'] ?? null;

    // Validate
    $validator = new AnmeldungValidator();
    $validationData = [
        'formular' => $formKey,
        'name' => $name,
        'email' => $email
    ];

    if (!$validator->validate($validationData)) {
        throw new RuntimeException(
            'Validation failed: ' . $validator->getFirstError(),
            400
        );
    }

    // Prepare for database
    $repository = new AnmeldungRepository();
    
    $insertData = [
        'formular' => $formKey,
        'formular_version' => $metadata['version'] ?? '1.0',
        'name' => $name,
        'email' => $email,
        'status' => 'neu',
        'data' => json_encode($data, JSON_UNESCAPED_UNICODE)
    ];

    // Insert into database
    $id = $repository->insert($insertData);

    if (!$id) {
        throw new RuntimeException('Failed to save anmeldung', 500);
    }

    // Log successful submission
    error_log(sprintf(
        'New anmeldung submitted: ID=%d, Form=%s, Email=%s',
        $id,
        $formKey,
        $email ?? 'none'
    ));

    // Return success
    http_response_code(201);
    echo json_encode([
        'success' => true,
        'id' => $id
    ]);

} catch (JsonException $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => 'Invalid JSON'
    ]);

} catch (RuntimeException $e) {
    http_response_code($e->getCode() ?: 400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);

} catch (Throwable $e) {
    error_log('Unexpected error in submit.php: ' . $e->getMessage());
    error_log('Stack trace: ' . $e->getTraceAsString());

    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Internal server error'
    ]);
}