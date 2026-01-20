<?php
declare(strict_types=1);

/**
 * Messages JSON API Endpoint
 *
 * Provides all frontend messages as JSON for JavaScript consumption.
 * Includes both base messages and local overrides.
 */

require_once __DIR__ . '/../../inc/bootstrap.php';

use Frontend\Services\MessageService;

// Set JSON headers
header('Content-Type: application/json; charset=utf-8');

// Cache for 1 hour (3600 seconds)
// Messages don't change often, so caching is beneficial
header('Cache-Control: public, max-age=3600');
header('Expires: ' . gmdate('D, d M Y H:i:s', time() + 3600) . ' GMT');

// Get all messages (including local overrides)
$messages = MessageService::getAll();

// Output as JSON
echo json_encode($messages, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
