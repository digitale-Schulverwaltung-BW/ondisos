<?php
// src/Config/EnvLoader.php
// simple .env parser and loader

declare(strict_types=1);

namespace App\Config;

class EnvLoader
{
    /**
     * Load .env file and set environment variables
     */
    public static function load(string $path): void
    {
        if (!file_exists($path)) {
            throw new \RuntimeException("Environment file not found: $path");
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        
        foreach ($lines as $line) {
            // Skip comments and empty lines
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            // Parse KEY=VALUE
            if (str_contains($line, '=')) {
                [$name, $value] = explode('=', $line, 2);
                $name = trim($name);
                $value = trim($value);

                // Remove quotes if present
                $value = trim($value, '"\'');

                // Set in $_ENV, $_SERVER and putenv
                $_ENV[$name] = $value;
                $_SERVER[$name] = $value;
                putenv("$name=$value");
            }
        }
    }

    /**
     * Get environment variable with optional default
     */
    public static function get(string $key, mixed $default = null): mixed
    {
        return $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key) ?: $default;
    }

    /**
     * Get required environment variable (throws if missing)
     */
    public static function require(string $key): string
    {
        $value = self::get($key);
        
        if ($value === null || $value === '') {
            throw new \RuntimeException("Required environment variable not set: $key");
        }

        return (string)$value;
    }
}