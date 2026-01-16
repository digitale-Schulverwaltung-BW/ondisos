<?php
// src/Config/Config.php

declare(strict_types=1);

namespace App\Config;

class Config
{
    private static ?self $instance = null;
    
    private function __construct(
        public readonly string $dbHost,
        public readonly int $dbPort,
        public readonly string $dbName,
        public readonly string $dbUser,
        public readonly string $dbPass,
        public readonly string $appEnv,
        public readonly bool $appDebug,
        public readonly int $sessionLifetime,
        public readonly bool $sessionSecure,
        public readonly string $logLevel,
        public readonly ?string $logFile,
        public readonly int $autoExpungeDays,
        public readonly bool $autoMarkAsRead,
    ) {}

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = self::loadFromEnv();
        }

        return self::$instance;
    }

    private static function loadFromEnv(): self
    {
        return new self(
            dbHost: EnvLoader::require('DB_HOST'),
            dbPort: (int)EnvLoader::get('DB_PORT', 3306),
            dbName: EnvLoader::require('DB_NAME'),
            dbUser: EnvLoader::require('DB_USER'),
            dbPass: EnvLoader::require('DB_PASS'),
            appEnv: EnvLoader::get('APP_ENV', 'production'),
            appDebug: filter_var(EnvLoader::get('APP_DEBUG', false), FILTER_VALIDATE_BOOLEAN),
            sessionLifetime: (int)EnvLoader::get('SESSION_LIFETIME', 3600),
            sessionSecure: filter_var(EnvLoader::get('SESSION_SECURE', true), FILTER_VALIDATE_BOOLEAN),
            logLevel: EnvLoader::get('LOG_LEVEL', 'error'),
            logFile: EnvLoader::get('LOG_FILE'),
            autoExpungeDays: (int)EnvLoader::get('AUTO_EXPUNGE_DAYS', 0),
            autoMarkAsRead: filter_var(EnvLoader::get('AUTO_MARK_AS_READ', false), FILTER_VALIDATE_BOOLEAN),
        );
    }

    public function isProduction(): bool
    {
        return $this->appEnv === 'production';
    }

    public function isDevelopment(): bool
    {
        return $this->appEnv === 'development';
    }
}