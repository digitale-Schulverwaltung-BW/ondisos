<?php
// inc/bootstrap.php

declare(strict_types=1);

// Error handling
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

// Load Composer autoloader (for external packages like mPDF)
$composerAutoload = __DIR__ . '/../vendor/autoload.php';
if (file_exists($composerAutoload)) {
    require_once $composerAutoload;
}

// Autoloader for App\* classes
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

// Force HTTPS in production (if enabled)
// This is a fallback - primary enforcement should be via Apache/Nginx config
$forceHttps = filter_var($_ENV['FORCE_HTTPS'] ?? 'false', FILTER_VALIDATE_BOOLEAN);
if ($forceHttps && php_sapi_name() !== 'cli') {
    // Check if request is not already HTTPS
    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https')
        || (!empty($_SERVER['HTTP_X_FORWARDED_SSL']) && $_SERVER['HTTP_X_FORWARDED_SSL'] === 'on')
        || (isset($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443);

    if (!$isHttps) {
        $redirectUrl = 'https://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . ($_SERVER['REQUEST_URI'] ?? '/');
        header('Location: ' . $redirectUrl, true, 301);
        exit('Redirecting to HTTPS...');
    }
}

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