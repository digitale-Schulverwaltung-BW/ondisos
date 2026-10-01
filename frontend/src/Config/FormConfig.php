<?php
// frontend/src/Config/FormConfig.php

declare(strict_types=1);

namespace Frontend\Config;

class FormConfig
{
    private static ?array $config = null;

    /**
     * Load configuration from an injected array.
     *
     * Called by index.php after fetching config from the backend API.
     * Replaces the previous file-based load(); all other methods are unchanged.
     *
     * @param array $config Full form-config array, e.g. ['bs' => [...], 'bk' => [...]]
     */
    public static function load(array $config): void
    {
        self::$config = $config;
    }

    /**
     * Get configuration for a specific form.
     * Requires load() to have been called first.
     */
    public static function get(string $formKey): ?array
    {
        return self::$config[$formKey] ?? null;
    }

    /**
     * Check if form exists.
     * Requires load() to have been called first.
     */
    public static function exists(string $formKey): bool
    {
        return isset(self::$config[$formKey]);
    }

    /**
     * Get all form keys.
     * Requires load() to have been called first.
     *
     * @return string[]
     */
    public static function getAllFormKeys(): array
    {
        return array_keys(self::$config ?? []);
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

        return __DIR__ . '/../../surveys/' . self::safeFileName($config['form'] ?? '');
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

        return __DIR__ . '/../../surveys/' . self::safeFileName($config['theme'] ?? '');
    }

    /**
     * File names from the configuration end up in a path: only plain names (no directories) are allowed.
     */
    private static function safeFileName(mixed $name): string
    {
        if (!is_string($name) || preg_match('/^[A-Za-z0-9_][A-Za-z0-9._-]*\.json$/D', $name) !== 1) {
            throw new \InvalidArgumentException('Invalid survey file name in form configuration');
        }
        return $name;
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
     *
     * @return ?string Single email or comma-separated list of emails
     */
    /**
     * Would a submission of this form be thrown away?
     *
     * True when the form is neither stored in the database (`db` false) nor reported by e-mail
     * (`notify_email` missing or invalid): the data would go nowhere while the visitor sees a success page.
     * Such a form is a configuration error and must not accept submissions.
     */
    public static function discardsSubmissions(string $formKey): bool
    {
        return self::get($formKey) !== null
            && !self::shouldSaveToDb($formKey)
            && self::getNotificationEmail($formKey) === null;
    }

    public static function getNotificationEmail(string $formKey): ?string
    {
        $config = self::get($formKey);
        $email = $config['notify_email'] ?? null;

        if (!$email) {
            return null;
        }

        // Support both string and array formats
        if (is_array($email)) {
            // Array format: ['email1@test.com', 'email2@test.com']
            $recipients = array_map('trim', $email);
        } else {
            // String format: 'email1@test.com, email2@test.com'
            $recipients = array_map('trim', explode(',', $email));
        }

        // Validate all email addresses
        foreach ($recipients as $recipient) {
            if (!filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
                // Invalid email address found - skip this form's notifications
                error_log("Invalid email address in notify_email for form '{$formKey}': {$recipient}");
                return null;
            }
        }

        // Return as comma-separated string (EmailService expects string)
        return implode(', ', $recipients);
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
     * Get PDF configuration for form
     * Returns null if PDF is not configured for this form
     *
     * @return array|null PDF config array or null
     */
    public static function getPdfConfig(string $formKey): ?array
    {
        $config = self::get($formKey);
        return $config['pdf'] ?? null;
    }
}