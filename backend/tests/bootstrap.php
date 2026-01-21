<?php
declare(strict_types=1);

/**
 * PHPUnit Bootstrap File
 *
 * Sets up the test environment and loads necessary files
 */

// Load Composer autoloader
$autoloadPath = __DIR__ . '/../vendor/autoload.php';
if (!file_exists($autoloadPath)) {
    die("Composer autoloader not found. Run 'composer install' first.\n");
}

require_once $autoloadPath;

// Load environment variables for testing
$envPath = __DIR__ . '/../.env.test';
if (file_exists($envPath)) {
    App\Config\EnvLoader::load($envPath);
}

// Set timezone
date_default_timezone_set('Europe/Berlin');

// Disable output buffering during tests
ini_set('output_buffering', 'off');
ini_set('implicit_flush', '1');

// Error reporting for tests
error_reporting(E_ALL);
ini_set('display_errors', '1');

// Define testing constants
define('TESTING', true);
define('SKIP_AUTO_EXPUNGE', true);
define('SKIP_AUTH_CHECK', true);

echo "PHPUnit Bootstrap loaded successfully.\n";
