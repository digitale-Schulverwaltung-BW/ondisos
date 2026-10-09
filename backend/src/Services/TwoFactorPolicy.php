<?php
// src/Services/TwoFactorPolicy.php
// Which admins need a second factor, and what happens after a correct password

declare(strict_types=1);

namespace App\Services;

use App\Config\EnvLoader;

/**
 * Pure decision logic for the login flow.
 *
 * TWO_FACTOR_REQUIRED:
 *   platform (default)  platform admins, only with MULTI_TENANT_ENABLED=true (multi-tenant operation
 *                       is the best indicator that the instance is reachable from the internet)
 *   tenants             tenant admins
 *   all                 everyone
 *   off                 no check at all, even for enrolled admins (emergency exit, e.g. TWO_FACTOR_KEY lost)
 *
 * Admins who are not required can still enrol voluntarily; once enrolled they must use the factor.
 */
final class TwoFactorPolicy
{
    public const OUTCOME_NONE = 'none';
    public const OUTCOME_CODE = 'code';
    public const OUTCOME_GRACE = 'grace';
    public const OUTCOME_SETUP_REQUIRED = 'setup_required';

    private string $mode;

    public function __construct(string $mode, private readonly bool $multiTenant, private readonly int $graceLogins)
    {
        $mode = strtolower(trim($mode));
        $this->mode = in_array($mode, ['platform', 'tenants', 'all', 'off'], true) ? $mode : 'platform';
    }

    public static function fromEnvironment(): self
    {
        return new self(
            (string)EnvLoader::get('TWO_FACTOR_REQUIRED', 'platform'),
            filter_var(EnvLoader::get('MULTI_TENANT_ENABLED', 'false'), FILTER_VALIDATE_BOOLEAN),
            max(0, (int)EnvLoader::get('TWO_FACTOR_GRACE_LOGINS', '10'))
        );
    }

    public function isDisabled(): bool
    {
        return $this->mode === 'off';
    }

    public function isRequired(bool $isPlatformAdmin): bool
    {
        return match ($this->mode) {
            'platform' => $isPlatformAdmin && $this->multiTenant,
            'tenants' => !$isPlatformAdmin,
            'all' => true,
            default => false,
        };
    }

    public function graceLogins(): int
    {
        return $this->graceLogins;
    }

    /**
     * What to do after a correct password and a passed network check.
     *
     * CODE            ask for the second factor
     * GRACE           log in, count the login, point to the setup (grace logins left)
     * SETUP_REQUIRED  no session: the setup wizard comes first
     * NONE            log in as before
     */
    public function decide(bool $isPlatformAdmin, bool $hasFactor, int $graceLoginsUsed): string
    {
        if ($this->isDisabled()) {
            return self::OUTCOME_NONE;
        }
        if ($hasFactor) {
            return self::OUTCOME_CODE;
        }
        if (!$this->isRequired($isPlatformAdmin)) {
            return self::OUTCOME_NONE;
        }

        return $graceLoginsUsed < $this->graceLogins ? self::OUTCOME_GRACE : self::OUTCOME_SETUP_REQUIRED;
    }
}
