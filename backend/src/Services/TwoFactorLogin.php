<?php
// src/Services/TwoFactorLogin.php
// Second-factor step of the login: what happens after a correct password

declare(strict_types=1);

namespace App\Services;

use App\Repositories\TwoFactorRepository;

final class TwoFactorLogin
{
    public const OUTCOME_ERROR = 'error';

    public function __construct(
        private readonly TwoFactorPolicy $policy,
        private readonly TwoFactorRepository $repository,
        private readonly bool $keyConfigured
    ) {
    }

    public static function fromEnvironment(): self
    {
        return new self(TwoFactorPolicy::fromEnvironment(), new TwoFactorRepository(), TwoFactorCrypto::isConfigured());
    }

    /**
     * @return array{outcome: string, message: ?string, graceLeft: int}
     *   outcome: a TwoFactorPolicy::OUTCOME_* value or OUTCOME_ERROR (no login, `message` says why)
     */
    public function evaluate(string $adminKey, bool $isPlatformAdmin): array
    {
        if ($this->policy->isDisabled()) {
            return $this->result(TwoFactorPolicy::OUTCOME_NONE);
        }

        try {
            $outcome = $this->policy->decide(
                $isPlatformAdmin,
                $this->repository->hasFactor($adminKey),
                $this->repository->graceLoginsUsed($adminKey)
            );
        } catch (\Throwable $e) {
            error_log('Two-factor lookup failed: ' . $e->getMessage());
            // Without a readable state a required second factor cannot be honoured: no login.
            // For admins who are not required (and cannot have a factor without the tables) carry on.
            return $this->policy->isRequired($isPlatformAdmin)
                ? $this->error('Die Zwei-Faktor-Prüfung ist derzeit nicht verfügbar (Datenbank-Migration ausgeführt? php migrate.php).')
                : $this->result(TwoFactorPolicy::OUTCOME_NONE);
        }

        if ($outcome === TwoFactorPolicy::OUTCOME_NONE) {
            return $this->result($outcome);
        }

        if (!$this->keyConfigured) {
            if ($outcome === TwoFactorPolicy::OUTCOME_GRACE) {
                // Setup is impossible without the key, so do not use up grace logins
                return $this->result(TwoFactorPolicy::OUTCOME_NONE, 'TWO_FACTOR_KEY fehlt: Die Zwei-Faktor-Anmeldung kann nicht eingerichtet werden. Erzeugen Sie einen Schlüssel mit „openssl rand -hex 32“ und tragen Sie ihn in der .env ein.');
            }

            return $this->error('Die Zwei-Faktor-Anmeldung ist nicht eingerichtet: TWO_FACTOR_KEY fehlt in der .env (Schlüssel erzeugen mit „openssl rand -hex 32“). Notausstieg: TWO_FACTOR_REQUIRED=off.');
        }

        if ($outcome === TwoFactorPolicy::OUTCOME_GRACE) {
            $used = $this->repository->graceLoginsUsed($adminKey);
            $this->repository->incrementGraceLogins($adminKey);

            return $this->result($outcome, null, max(0, $this->policy->graceLogins() - $used - 1));
        }

        return $this->result($outcome);
    }

    /** @return array{outcome: string, message: ?string, graceLeft: int} */
    private function result(string $outcome, ?string $message = null, int $graceLeft = 0): array
    {
        return ['outcome' => $outcome, 'message' => $message, 'graceLeft' => $graceLeft];
    }

    /** @return array{outcome: string, message: ?string, graceLeft: int} */
    private function error(string $message): array
    {
        return $this->result(self::OUTCOME_ERROR, $message);
    }
}
