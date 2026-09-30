<?php
// backend/src/Config/FormConfig.php

declare(strict_types=1);

namespace App\Config;

use mysqli;

/**
 * DB-backed form configuration accessor.
 *
 * Reads form configuration from the form_configs table, scoped to the
 * current tenant via TenantContext::getTenantId(). Each config_json
 * column value is a JSON blob that mirrors the former PHP array structure.
 *
 * Cache: results are cached per request in a static array keyed by form_key,
 * so repeated calls for the same key do not re-query the DB.
 *
 * Test isolation: setConnectionForTesting() and reset() are provided so
 * unit tests can inject a mock mysqli without a live database.
 */
class FormConfig
{
    /** @var array<string, array<string,mixed>|null> Cache keyed by formKey; null means "not found" */
    private static array $cache = [];

    /** @var mysqli|null Injected connection for unit testing; null = use Database::getConnection() */
    private static ?mysqli $testConnection = null;

    // =========================================================================
    // Core API
    // =========================================================================

    /**
     * Get configuration for a specific form, scoped to the current tenant.
     *
     * @return array<string,mixed>|null The decoded config array, or null if not found.
     */
    public static function get(string $formKey): ?array
    {
        if (array_key_exists($formKey, self::$cache)) {
            return self::$cache[$formKey];
        }

        $db       = self::$testConnection ?? Database::getConnection();
        $tenantId = TenantContext::getTenantId();

        $stmt = $db->prepare(
            'SELECT config_json FROM form_configs WHERE form_key = ? AND tenant_id = ? LIMIT 1'
        );
        $stmt->bind_param('si', $formKey, $tenantId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();

        $config = $row ? json_decode($row['config_json'], true) : null;

        self::$cache[$formKey] = $config;

        return $config;
    }

    /**
     * Check if a form configuration exists for the current tenant.
     */
    public static function exists(string $formKey): bool
    {
        return self::get($formKey) !== null;
    }

    /**
     * Get all form keys configured for the current tenant.
     *
     * @return string[]
     */
    public static function getAllFormKeys(): array
    {
        $db       = self::$testConnection ?? Database::getConnection();
        $tenantId = TenantContext::getTenantId();

        $stmt = $db->prepare(
            'SELECT form_key FROM form_configs WHERE tenant_id = ? ORDER BY form_key ASC'
        );
        $stmt->bind_param('i', $tenantId);
        $stmt->execute();
        $result = $stmt->get_result();

        $keys = [];
        while ($row = $result->fetch_assoc()) {
            $keys[] = $row['form_key'];
        }

        return $keys;
    }

    /**
     * Get the version string for a form (defaults to '1.0.0' if not set).
     */
    public static function getVersion(string $formKey): string
    {
        $config = self::get($formKey);
        return $config['version'] ?? '1.0.0';
    }

    /**
     * Get PDF configuration for a form, or null if PDF is not enabled.
     *
     * @return array<string,mixed>|null
     */
    public static function getPdfConfig(string $formKey): ?array
    {
        $config = self::get($formKey);

        if ($config === null) {
            return null;
        }

        $pdfConfig = $config['pdf'] ?? null;

        // Only return if PDF is explicitly enabled
        if ($pdfConfig && ($pdfConfig['enabled'] ?? false)) {
            return $pdfConfig;
        }

        return null;
    }

    // =========================================================================
    // Test helpers
    // =========================================================================

    /**
     * Clear the static cache.
     *
     * Called in test tearDown() to prevent cross-test cache pollution.
     * Also useful when TenantContext changes within a request (unusual but valid).
     */
    public static function reset(): void
    {
        self::$cache = [];
    }

    /**
     * Inject a mock mysqli connection for unit tests.
     *
     * Pass null to restore normal Database::getConnection() behavior.
     * Must be paired with reset() in tearDown() to avoid leaking state.
     */
    public static function setConnectionForTesting(?mysqli $db): void
    {
        self::$testConnection = $db;
    }
}
