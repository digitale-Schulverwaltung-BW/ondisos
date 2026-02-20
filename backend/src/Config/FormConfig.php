<?php
// backend/src/Config/FormConfig.php

declare(strict_types=1);

namespace App\Config;

class FormConfig
{
    private static ?array $config = null;

    /**
     * Load configuration from backend config directory
     */
    public static function load(): void
    {
        if (self::$config !== null) {
            return;
        }

        // Try backend-specific config first, then fall back to frontend config (shared)
        $backendConfigFile = __DIR__ . '/../../config/forms-config.php';
        $frontendConfigFile = __DIR__ . '/../../../frontend/config/forms-config.php';

        $configFile = null;
        if (file_exists($backendConfigFile)) {
            $configFile = $backendConfigFile;
        } elseif (file_exists($frontendConfigFile)) {
            $configFile = $frontendConfigFile;
        }

        if ($configFile === null) {
            // No config file found - initialize as empty array
            // This is expected when frontend sends PDF config in payload
            self::$config = [];
            return;
        }

        self::$config = require $configFile;
    }

    /**
     * Get configuration for a specific form
     */
    public static function get(string $formKey): ?array
    {
        self::load();
        return self::$config[$formKey] ?? null;
    }

    /**
     * Check if form exists
     */
    public static function exists(string $formKey): bool
    {
        self::load();
        return isset(self::$config[$formKey]);
    }

    /**
     * Get all form keys
     *
     * @return string[]
     */
    public static function getAllFormKeys(): array
    {
        self::load();
        return array_keys(self::$config);
    }

    /**
     * Get form version
     */
    public static function getVersion(string $formKey): string
    {
        $config = self::get($formKey);
        return $config['version'] ?? '1.0.0';
    }

    /**
     * Get PDF configuration for a form
     *
     * @return array|null PDF config or null if not configured
     */
    public static function getPdfConfig(string $formKey): ?array
    {
        $config = self::get($formKey);

        if ($config === null) {
            return null;
        }

        $pdfConfig = $config['pdf'] ?? null;

        // Only return if PDF is enabled
        if ($pdfConfig && ($pdfConfig['enabled'] ?? false)) {
            return $pdfConfig;
        }

        return null;
    }
}
