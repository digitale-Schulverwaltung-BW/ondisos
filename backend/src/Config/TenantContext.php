<?php
declare(strict_types=1);

namespace App\Config;

use RuntimeException;

/**
 * TenantContext — static singleton for request-scoped tenant identity.
 *
 * Every PHP request MUST call either initialize() or initAllTenants() exactly once
 * (typically in bootstrap.php) before any repository or service calls getTenantId().
 * Failure to initialize will throw a RuntimeException, making initialization bugs
 * immediately visible rather than causing silent cross-tenant data leakage.
 *
 * Usage (normal tenant request):
 *   TenantContext::initialize($tenantId);
 *   $tenantId = TenantContext::getTenantId();
 *
 * Usage (platform admin all-tenants view):
 *   TenantContext::initAllTenants();
 *   // getTenantId() is NOT available — use isAllTenants() guard before calling
 *   if (!TenantContext::isAllTenants()) {
 *       $id = TenantContext::getTenantId();
 *   }
 *
 * Note: reset() is intended for test teardown only.
 */
class TenantContext
{
    private static ?int $tenantId = null;
    private static bool $allTenants = false;

    private function __construct() {}

    public static function initialize(int $tenantId): void
    {
        self::$tenantId = $tenantId;
        self::$allTenants = false;
    }

    /**
     * Activate platform-admin all-tenants mode.
     *
     * After calling this, isAllTenants() returns true and getTenantId() throws.
     * Callers (e.g. AnmeldungRepository) must guard with isAllTenants() before
     * calling getTenantId() to avoid erroneous single-tenant queries.
     */
    public static function initAllTenants(): void
    {
        self::$allTenants = true;
        self::$tenantId = null;
    }

    /**
     * Returns true when the context is in all-tenants mode (platform admin view).
     */
    public static function isAllTenants(): bool
    {
        return self::$allTenants;
    }

    public static function getTenantId(): int
    {
        if (self::$allTenants) {
            throw new RuntimeException(
                'TenantContext is in all-tenants mode. getTenantId() is not available.'
            );
        }
        if (self::$tenantId === null) {
            throw new RuntimeException(
                'TenantContext not initialized. Call TenantContext::initialize() first.'
            );
        }
        return self::$tenantId;
    }

    public static function reset(): void
    {
        self::$tenantId = null;
        self::$allTenants = false;
    }
}
