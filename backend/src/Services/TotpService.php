<?php
// src/Services/TotpService.php
// TOTP (RFC 6238) for the second factor

declare(strict_types=1);

namespace App\Services;

use OTPHP\TOTP;
use ParagonIE\ConstantTime\Base32;

/**
 * Wraps the OTPHP library: 6 digits, 30 seconds, SHA-1 (what authenticator apps support everywhere).
 *
 * Replay protection: verify() only accepts time steps greater than the last accepted one, so a code
 * that was used once (or an older one) is rejected even inside its validity window.
 */
final class TotpService
{
    public const PERIOD = 30;
    public const ISSUER = 'ondisos';

    public function generateSecret(): string
    {
        // 160 bits as recommended by RFC 4226 (the library default of 512 bits makes a needlessly large QR code)
        return Base32::encodeUpperUnpadded(random_bytes(20));
    }

    public function provisioningUri(string $secret, string $account): string
    {
        $totp = TOTP::createFromSecret($secret);
        $totp->setLabel($account);
        $totp->setIssuer(self::ISSUER);

        return $totp->getProvisioningUri();
    }

    /**
     * Whether the code is valid now (±1 time step) and newer than the last accepted step.
     *
     * @return int|null the accepted time step (store it as the new "last step"), null if rejected
     */
    public function verify(string $secret, string $code, ?int $lastStep, ?int $now = null): ?int
    {
        $code = preg_replace('/\s+/', '', $code) ?? '';
        if (preg_match('/^\d{6}$/', $code) !== 1) {
            return null;
        }

        $currentStep = intdiv($now ?? time(), self::PERIOD);
        $totp = TOTP::createFromSecret($secret);

        $accepted = null;
        foreach ([$currentStep - 1, $currentStep, $currentStep + 1] as $step) {
            // Compare all three steps without early exit
            if (hash_equals($totp->at($step * self::PERIOD), $code) && $accepted === null) {
                $accepted = $step;
            }
        }
        if ($accepted === null || ($lastStep !== null && $accepted <= $lastStep)) {
            return null;
        }

        return $accepted;
    }

    /** Code for a time (tests, setup checks). */
    public function codeAt(string $secret, int $timestamp): string
    {
        return TOTP::createFromSecret($secret)->at($timestamp);
    }
}
