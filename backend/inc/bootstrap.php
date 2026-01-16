<?php
// inc/bootstrap.php

declare(strict_types=1);

// Error handling
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

// Autoloader
spl_autoload_register(function (string $class) {
    // Convert namespace to file path
    // App\Models\Anmeldung -> src/Models/Anmeldung.php
    $prefix = 'App\\';
    $baseDir = __DIR__ . '/../src/';

    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) {
        return;
    }

    $relativeClass = substr($class, $len);
    $file = $baseDir . str_replace('\\', '/', $relativeClass) . '.php';

    if (file_exists($file)) {
        require $file;
    }
});

// Load environment variables
$envFile = __DIR__ . '/../.env';
if (file_exists($envFile)) {
    App\Config\EnvLoader::load($envFile);
}

// Set error handler
set_error_handler(function (int $errno, string $errstr, string $errfile, int $errline) {
    error_log("Error [$errno]: $errstr in $errfile on line $errline");
    
    if (!(error_reporting() & $errno)) {
        return false;
    }
    
    throw new ErrorException($errstr, 0, $errno, $errfile, $errline);
});

// Set exception handler
set_exception_handler(function (Throwable $e) {
    error_log('Uncaught exception: ' . $e->getMessage());
    http_response_code(500);
    
    if (ini_get('display_errors')) {
        echo '<pre>Error: ' . htmlspecialchars($e->getMessage()) . '</pre>';
    } else {
        echo 'Ein Fehler ist aufgetreten. Bitte versuchen Sie es später erneut.';
    }
    exit;
});

// Run auto-expunge (throttled, only runs every X hours)
// This is non-blocking and won't affect page load
if (!defined('SKIP_AUTO_EXPUNGE')) {
    try {
        $expungeRepo = new \App\Repositories\AnmeldungRepository();
        $expungeService = new \App\Services\ExpungeService($expungeRepo);
        $requestExpunge = new \App\Services\RequestExpungeService($expungeService);
        $requestExpunge->checkAndRun();
    } catch (\Throwable $e) {
        // Silently fail - don't break the application
        error_log('Auto-expunge check failed: ' . $e->getMessage());
    }
}