<?php
// src/Services/AdminNetworkPolicy.php
// Network allowlist for admin logins and admin sessions

declare(strict_types=1);

namespace App\Services;

use App\Config\EnvLoader;
use App\Repositories\TenantRepository;
use App\Utils\CidrList;

/**
 * Decides whether an admin may use the backend from a given client IP.
 *
 * - Platform admins (and every browser user when login is switched off) are checked against the
 *   global list PLATFORM_ADMIN_ALLOWED_NETWORKS from the environment.
 * - Tenant admins are checked against tenants.admin_allowed_networks of their tenant.
 *
 * An empty list means "no restriction". A list that is set but contains no valid entry
 * (typo) denies everything instead of silently opening up.
 */
final class AdminNetworkPolicy
{
    /** @var callable(int): string */
    private $tenantNetworks;

    /**
     * @param callable(int): string $tenantNetworks returns the allowlist text of a tenant ('' = none)
     */
    public function __construct(private readonly string $platformNetworks, callable $tenantNetworks)
    {
        $this->tenantNetworks = $tenantNetworks;
    }

    public static function fromEnvironment(): self
    {
        return new self(
            (string)EnvLoader::get('PLATFORM_ADMIN_ALLOWED_NETWORKS', ''),
            static function (int $tenantId): string {
                return (new TenantRepository())->getAdminAllowedNetworks($tenantId);
            }
        );
    }

    public function isAllowed(?int $tenantId, bool $isPlatformAdmin, string $ip): bool
    {
        if ($isPlatformAdmin) {
            return self::allows($this->platformNetworks, $ip);
        }
        if ($tenantId === null) {
            // Neither platform admin nor tenant: no valid context, so no access
            return false;
        }

        return self::allows(($this->tenantNetworks)($tenantId), $ip);
    }

    /**
     * Browser access without login (AUTH_ENABLED=false, single tenant): everyone counts as platform admin.
     */
    public function isAllowedWithoutLogin(string $ip): bool
    {
        return self::allows($this->platformNetworks, $ip);
    }

    public static function allows(string $networks, string $ip): bool
    {
        if (trim($networks) === '') {
            return true;
        }

        return CidrList::parse($networks)->contains($ip);
    }
}
