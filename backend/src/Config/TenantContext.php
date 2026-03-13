<?php
declare(strict_types=1);

namespace App\Config;

/**
 * TenantContext stub — intentionally incomplete for RED phase.
 * See Task 2 for full implementation.
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
        // Stub: returns 0 instead of throwing — RED state intentional
        return self::$tenantId ?? 0;
    }

    public static function reset(): void
    {
        self::$tenantId = null;
    }
}
