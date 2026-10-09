<?php
declare(strict_types=1);

namespace App\Config;

use RuntimeException;

/**
 * TenantContext — static singleton for request-scoped tenant identity.
 *
 * Every request that touches tenant data MUST call initialize() exactly once
 * (typically in bootstrap.php) before any repository or service calls getTenantId().
 * Failure to initialize will throw a RuntimeException, making initialization bugs
 * immediately visible rather than causing silent cross-tenant data leakage.
 * There is deliberately no "all tenants" mode: a platform admin works in one tenant at a time.
 *
 * Usage (normal tenant request):
 *   TenantContext::initialize($tenantId);
 *   $tenantId = TenantContext::getTenantId();
 *
 * Note: reset() is intended for test teardown only.
 */
class TenantContext
{
    private static ?int $tenantId = null;

    private function __construct() {}

    public static function initialize(int $tenantId): void
    {
        self::$tenantId = $tenantId;
    }

    /**
     * True once a tenant is active (platform admin before choosing a school, login and 401 API requests: false).
     */
    public static function isInitialized(): bool
    {
        return self::$tenantId !== null;
    }

    public static function getTenantId(): int
    {
        if (self::$tenantId === null) {
            throw new RuntimeException(
                'TenantContext not initialized. Call TenantContext::initialize() first.'
            );
        }
        return self::$tenantId;
    }

    /**
     * Run $fn as another tenant, then restore the previous context exactly (including "not initialized").
     *
     * For the few operations that must read one tenant and write another (copying forms). Everything inside $fn still goes
     * through the tenant-filtered repositories; this only changes which tenant they see.
     *
     * @template T
     * @param callable(): T $fn
     * @return T
     */
    public static function runAs(int $tenantId, callable $fn): mixed
    {
        $previousTenant = self::$tenantId;

        self::$tenantId = $tenantId;
        try {
            return $fn();
        } finally {
            self::$tenantId = $previousTenant;
        }
    }

    public static function reset(): void
    {
        self::$tenantId = null;
    }
}
