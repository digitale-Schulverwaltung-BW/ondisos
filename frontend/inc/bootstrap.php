<?php
// frontend/inc/bootstrap.php

declare(strict_types=1);

// Error handling
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

// Autoloader
spl_autoload_register(function (string $class) {
    $prefix = 'Frontend\\';
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

// Load environment variables (if .env exists)
$envFile = __DIR__ . '/../.env';
if (file_exists($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    
    foreach ($lines as $line) {
        $line = trim($line);
        
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }

        if (str_contains($line, '=')) {
            [$name, $value] = explode('=', $line, 2);
            $name = trim($name);
            $value = trim($value, '"\'');
            
            putenv("$name=$value");
            $_ENV[$name] = $value;
            $_SERVER[$name] = $value;
        }
    }
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
    error_log('Stack trace: ' . $e->getTraceAsString());
    
    http_response_code(500);
    
    if (ini_get('display_errors')) {
        echo '<pre>Error: ' . htmlspecialchars($e->getMessage()) . '</pre>';
    } else {
        echo 'Ein Fehler ist aufgetreten. Bitte versuchen Sie es später erneut.';
    }
    exit;
});

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}