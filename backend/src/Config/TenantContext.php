<?php
declare(strict_types=1);

namespace App\Config;

use RuntimeException;

/**
 * TenantContext — static singleton for request-scoped tenant identity.
 *
 * Every PHP request MUST call initialize() exactly once (typically in bootstrap.php)
 * before any repository or service calls getTenantId(). Failure to initialize will
 * throw a RuntimeException, making initialization bugs immediately visible rather
 * than causing silent cross-tenant data leakage.
 *
 * Usage:
 *   // bootstrap.php
 *   TenantContext::initialize($tenantId);
 *
 *   // anywhere in the request lifecycle
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

    public static function getTenantId(): int
    {
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
    }
}
