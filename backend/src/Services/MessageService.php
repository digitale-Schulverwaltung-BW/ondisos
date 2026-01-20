<?php
declare(strict_types=1);

namespace App\Services;

/**
 * Central Message Management Service
 *
 * Provides centralized message storage with local override support.
 * Messages can be overridden in messages.local.php (gitignored) for
 * site-specific customizations without git conflicts.
 *
 * Features:
 * - Dot notation access (e.g., 'errors.generic_error')
 * - Placeholder replacement ({{variable}})
 * - Automatic contact info insertion
 * - Local overrides (messages.local.php)
 * - Fallback to default messages
 */
class MessageService
{
    private static ?array $messages = null;
    private static ?array $overrides = null;

    /**
     * Get message by dot notation key
     *
     * @param string $key Dot notation key (e.g., 'errors.generic_error')
     * @param string $default Default value if key not found
     * @return string The message or default value
     */
    public static function get(string $key, string $default = ''): string
    {
        self::load();

        // Merge base messages with local overrides
        $merged = array_replace_recursive(self::$messages, self::$overrides);

        // Navigate through nested array using dot notation
        $value = $merged;
        foreach (explode('.', $key) as $segment) {
            if (!isset($value[$segment])) {
                return $default ?: "[missing: $key]";
            }
            $value = $value[$segment];
        }

        return is_string($value) ? $value : $default;
    }

    /**
     * Format message with placeholder replacement
     *
     * Replaces {{key}} placeholders in message with provided values.
     *
     * Example:
     *   format('success.restored', ['id' => 42])
     *   → "Eintrag #42 wurde wiederhergestellt"
     *
     * @param string $key Message key
     * @param array<string, mixed> $replacements Associative array of replacements
     * @param string $default Default value if key not found
     * @return string Formatted message
     */
    public static function format(string $key, array $replacements = [], string $default = ''): string
    {
        $message = self::get($key, $default);

        foreach ($replacements as $k => $v) {
            $message = str_replace('{{' . $k . '}}', (string)$v, $message);
        }

        return $message;
    }

    /**
     * Format message with automatic contact placeholder
     *
     * Automatically includes contact info from contact.support_text.
     * Useful for error messages that should include support contact.
     *
     * Example:
     *   withContact('errors.generic_error')
     *   → "Ein Fehler ist aufgetreten. Bei Problemen: sekretariat@example.com"
     *
     * @param string $key Message key
     * @param array<string, mixed> $additionalReplacements Additional placeholders
     * @return string Formatted message with contact info
     */
    public static function withContact(string $key, array $additionalReplacements = []): string
    {
        $replacements = array_merge([
            'contact' => self::get('contact.support_text', '')
        ], $additionalReplacements);

        return self::format($key, $replacements);
    }

    /**
     * Get all messages (for API export)
     *
     * Returns the complete merged message array (base + overrides).
     * Useful for JSON API endpoints.
     *
     * @return array<string, mixed> All messages
     */
    public static function getAll(): array
    {
        self::load();
        return array_replace_recursive(self::$messages, self::$overrides);
    }

    /**
     * Load messages from config files
     *
     * Loads base messages from config/messages.php and optional
     * local overrides from config/messages.local.php.
     *
     * @return void
     */
    private static function load(): void
    {
        if (self::$messages !== null) {
            return; // Already loaded
        }

        $baseFile = __DIR__ . '/../../config/messages.php';
        $localFile = __DIR__ . '/../../config/messages.local.php';

        self::$messages = file_exists($baseFile) ? require $baseFile : [];
        self::$overrides = file_exists($localFile) ? require $localFile : [];
    }

    /**
     * Reset loaded messages (useful for testing)
     *
     * @return void
     */
    public static function reset(): void
    {
        self::$messages = null;
        self::$overrides = null;
    }
}
