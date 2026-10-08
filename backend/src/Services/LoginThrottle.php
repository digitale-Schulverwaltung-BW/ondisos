<?php
// src/Services/LoginThrottle.php

declare(strict_types=1);

namespace App\Services;

/**
 * Begrenzt fehlgeschlagene Anmeldeversuche (Brute-Force-Schutz).
 *
 * Zwei Zähler über dasselbe Zeitfenster:
 *   - pro IP + Benutzername (wenige Versuche: schützt ein einzelnes Konto),
 *   - pro IP (mehr Versuche: schützt vor dem Durchprobieren vieler Benutzernamen).
 * Der Zähler pro Konto hängt bewusst an der IP, damit Dritte ein Konto nicht
 * von außen sperren können.
 *
 * Gezählt werden nur Fehlversuche; ein erfolgreicher Login setzt den Zähler
 * des Kontos zurück.
 */
class LoginThrottle
{
    private RateLimiter $perAccount;
    private RateLimiter $perIp;

    public function __construct(
        string $storageDir,
        int $maxPerAccount = 5,
        int $maxPerIp = 20,
        private int $windowSeconds = 900
    ) {
        // Eigene Verzeichnisse: RateLimiter räumt je Verzeichnis nach dem eigenen Zeitfenster auf.
        $this->perAccount = new RateLimiter(rtrim($storageDir, '/') . '/account', $maxPerAccount, $windowSeconds, 5);
        $this->perIp = new RateLimiter(rtrim($storageDir, '/') . '/ip', $maxPerIp, $windowSeconds, 5);
    }

    public function isBlocked(string $ip, string $username): bool
    {
        return $this->perAccount->getRemainingRequests($this->accountKey($ip, $username)) === 0
            || $this->perIp->getRemainingRequests($ip) === 0;
    }

    /** Sekunden bis zur nächsten erlaubten Anmeldung (0, wenn nicht gesperrt). */
    public function retryAfter(string $ip, string $username): int
    {
        return max(
            $this->perAccount->getRetryAfter($this->accountKey($ip, $username)),
            $this->perIp->getRetryAfter($ip)
        );
    }

    public function recordFailure(string $ip, string $username): void
    {
        $this->perAccount->isAllowed($this->accountKey($ip, $username));
        $this->perIp->isAllowed($ip);
    }

    public function recordSuccess(string $ip, string $username): void
    {
        $this->perAccount->reset($this->accountKey($ip, $username));
    }

    private function accountKey(string $ip, string $username): string
    {
        return $ip . '|' . hash('sha256', mb_strtolower(trim($username)));
    }
}
