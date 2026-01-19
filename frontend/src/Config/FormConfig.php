<?php
// frontend/src/Config/FormConfig.php

declare(strict_types=1);

namespace Frontend\Config;

class FormConfig
{
    private static ?array $config = null;

    /**
     * Load configuration
     */
    public static function load(): void
    {
        if (self::$config !== null) {
            return;
        }

        $configFile = __DIR__ . '/../../config/forms-config.php';
        
        if (!file_exists($configFile)) {
            throw new \RuntimeException('Configuration file not found: ' . $configFile);
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
     * Validate form configuration
     */
    public static function validate(string $formKey): bool
    {
        $config = self::get($formKey);
        
        if ($config === null) {
            return false;
        }

        // Required fields
        $required = ['form', 'theme'];
        foreach ($required as $field) {
            if (!isset($config[$field]) || empty($config[$field])) {
                return false;
            }
        }

        return true;
    }

    /**
     * Get form file path
     */
    public static function getFormPath(string $formKey): string
    {
        $config = self::get($formKey);
        
        if ($config === null) {
            throw new \InvalidArgumentException("Unknown form: $formKey");
        }

        return __DIR__ . '/../../surveys/' . $config['form'];
    }

    /**
     * Get theme file path
     */
    public static function getThemePath(string $formKey): string
    {
        $config = self::get($formKey);
        
        if ($config === null) {
            throw new \InvalidArgumentException("Unknown form: $formKey");
        }

        return __DIR__ . '/../../surveys/' . $config['theme'];
    }

    /**
     * Get backend URL from environment
     */
    public static function getBackendUrl(): string
    {
        $url = getenv('BACKEND_API_URL') ?: 'http://localhost/backend/api';
        return rtrim($url, '/');
    }

    /**
     * Should this form be saved to database?
     */
    public static function shouldSaveToDb(string $formKey): bool
    {
        $config = self::get($formKey);
        return $config !== null && ($config['db'] ?? true);
    }

    /**
     * Get notification email for form
     */
    public static function getNotificationEmail(string $formKey): ?string
    {
        $config = self::get($formKey);
        $email = $config['notify_email'] ?? null;

        if ($email && filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $email;
        }

        return null;
    }

    /**
     * Get form version
     */
    public static function getVersion(string $formKey): string
    {
        $config = self::get($formKey);
        return $config['version'] ?? '1.0.0';
    }
}