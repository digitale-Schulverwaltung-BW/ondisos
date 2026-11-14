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
 * Ablauf: tryAttempt() zählt den Versuch, BEVOR das Passwort (oder der Code) geprüft wird; nur so
 * kommen parallele Anfragen nicht alle an der Sperre vorbei. Ein erfolgreicher Versuch setzt den
 * Zähler des Kontos zurück und gibt seinen Platz im IP-Zähler wieder frei, sodass im Ergebnis nur
 * Fehlversuche zählen (wichtig für viele Kollegen hinter einer Schul-IP).
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

    /**
     * Zählt einen Versuch atomar. false = gesperrt; dann wurde nichts gezählt, der Versuch darf
     * nicht geprüft werden. Auf true folgt bei Erfolg recordSuccess(), sonst nichts.
     */
    public function tryAttempt(string $ip, string $username): bool
    {
        $accountKey = $this->accountKey($ip, $username);
        if (!$this->perAccount->isAllowed($accountKey)) {
            return false;
        }
        if (!$this->perIp->isAllowed($ip)) {
            $this->perAccount->release($accountKey);
            return false;
        }

        return true;
    }

    /** Der Versuch wurde nicht geprüft (technischer Fehler): nicht als Fehlversuch zählen. */
    public function cancelAttempt(string $ip, string $username): void
    {
        $this->perAccount->release($this->accountKey($ip, $username));
        $this->perIp->release($ip);
    }

    /** Nach erfolgreicher Prüfung: Konto-Zähler zurücksetzen, den Versuch im IP-Zähler zurückgeben. */
    public function recordSuccess(string $ip, string $username): void
    {
        $this->perAccount->reset($this->accountKey($ip, $username));
        $this->perIp->release($ip);
    }

    private function accountKey(string $ip, string $username): string
    {
        return $ip . '|' . hash('sha256', mb_strtolower(trim($username)));
    }
}
