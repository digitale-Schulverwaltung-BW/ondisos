<?php
// src/Utils/ClientIdentity.php

declare(strict_types=1);

namespace App\Utils;

use App\Services\RateLimiter;

/**
 * Bezeichner eines Besuchers für das Rate-Limit der signierten API.
 *
 * Alle Anfragen kommen vom Frontend-Server; REMOTE_ADDR, User-Agent und Accept-Language
 * sind daher für alle Besucher gleich. Das Frontend schickt deshalb eine pseudonyme Kennung
 * (`X-Ondisos-Client`: HMAC der Besucher-IP mit dem Tenant-Secret). Fehlt sie (älteres Frontend),
 * gilt der bisherige Fingerabdruck.
 *
 * Die Kennung ist nicht Teil der Signatur: Sie begrenzt nur, sie authentifiziert nicht.
 */
final class ClientIdentity
{
    /** @param array<string,mixed> $server */
    public static function fingerprint(array $server, int $tenantId): string
    {
        $clientId = self::clientId($server);
        if ($clientId !== null) {
            return 'client:' . $tenantId . ':' . $clientId;
        }

        return RateLimiter::generateFingerprint($server);
    }

    /**
     * The pseudonymous id from X-Ondisos-Client, or null if the frontend sent none (or a malformed one).
     *
     * @param array<string,mixed> $server
     */
    public static function clientId(array $server): ?string
    {
        $header = $server['HTTP_X_ONDISOS_CLIENT'] ?? '';

        return is_string($header) && preg_match('/^[a-f0-9]{64}$/', $header) === 1 ? $header : null;
    }
}
