<?php
// frontend/src/Utils/ClientIp.php
// Client IP detection with optional trust in reverse proxies (TRUSTED_PROXIES)

declare(strict_types=1);

namespace Frontend\Utils;

/**
 * Determines the client IP stored with a registration.
 *
 * Port of App\Utils\ClientIp (backend); the frontend ships standalone and cannot share code with it.
 * Keep both in sync (tests/Unit/Frontend/ClientIpTest checks they agree).
 *
 * X-Forwarded-For is client-controlled and therefore ignored unless the direct peer
 * (REMOTE_ADDR) is listed in TRUSTED_PROXIES. Only then the header is evaluated, from right
 * to left: the first entry that is not itself a trusted proxy is the client.
 */
final class ClientIp
{
    public const UNKNOWN = 'unknown';

    /**
     * Client IP of the current request, using TRUSTED_PROXIES from the environment.
     *
     * @param array<string, mixed>|null $server $_SERVER (injectable for testing)
     */
    public static function get(?array $server = null): string
    {
        return self::resolve(
            $server ?? $_SERVER,
            self::parseTrustedProxies((string)(getenv('TRUSTED_PROXIES') ?: ''))
        );
    }

    /**
     * @param array<string, mixed> $server
     * @param list<string>         $trustedProxies IPs or CIDR ranges (see parseTrustedProxies())
     */
    public static function resolve(array $server, array $trustedProxies): string
    {
        $remote = $server['REMOTE_ADDR'] ?? null;
        if (!is_string($remote) || $remote === '') {
            return self::UNKNOWN;
        }

        $remoteIp = self::normalize($remote);
        if ($remoteIp === null) {
            return $remote;
        }

        $forwarded = $server['HTTP_X_FORWARDED_FOR'] ?? null;
        if ($trustedProxies === [] || !is_string($forwarded) || trim($forwarded) === ''
            || !self::isTrusted($remoteIp, $trustedProxies)) {
            return $remoteIp;
        }

        $entries = array_map('trim', explode(',', $forwarded));
        $client = $remoteIp;
        for ($i = count($entries) - 1; $i >= 0; $i--) {
            $ip = self::normalize($entries[$i]);
            if ($ip === null) {
                // Garbage in the chain: do not guess, fall back to the proxy address
                return $remoteIp;
            }
            $client = $ip;
            if (!self::isTrusted($ip, $trustedProxies)) {
                break;
            }
        }

        return $client;
    }

    /**
     * Parses a comma-separated list of IPs/CIDR ranges; invalid entries are dropped.
     *
     * @return list<string> normalized entries ("1.2.3.4", "10.0.0.0/8", "2001:db8::/32")
     */
    public static function parseTrustedProxies(string $value): array
    {
        $result = [];
        foreach (explode(',', $value) as $item) {
            $item = trim($item);
            if ($item === '') {
                continue;
            }
            $parts = explode('/', $item, 2);
            $ip = self::normalize($parts[0]);
            if ($ip === null) {
                continue;
            }
            if (count($parts) === 1) {
                $result[] = $ip;
                continue;
            }
            $max = str_contains($ip, ':') ? 128 : 32;
            if (!ctype_digit($parts[1]) || (int)$parts[1] > $max) {
                continue;
            }
            $result[] = $ip . '/' . (int)$parts[1];
        }

        return $result;
    }

    /**
     * Valid IP in canonical form (IPv4-mapped IPv6 as IPv4), otherwise null.
     */
    public static function normalize(string $ip): ?string
    {
        $bin = filter_var($ip, FILTER_VALIDATE_IP) !== false ? @inet_pton($ip) : false;
        if ($bin === false) {
            return null;
        }
        if (strlen($bin) === 16 && str_starts_with($bin, str_repeat("\0", 10) . "\xff\xff")) {
            $bin = substr($bin, 12);
        }

        return inet_ntop($bin) ?: null;
    }

    /**
     * @param list<string> $trustedProxies
     */
    private static function isTrusted(string $ip, array $trustedProxies): bool
    {
        foreach ($trustedProxies as $entry) {
            if (self::matches($ip, $entry)) {
                return true;
            }
        }

        return false;
    }

    private static function matches(string $ip, string $entry): bool
    {
        [$net, $bits] = array_pad(explode('/', $entry, 2), 2, null);
        $a = inet_pton($ip);
        $b = inet_pton((string)$net);
        if ($a === false || $b === false || strlen($a) !== strlen($b)) {
            return false;
        }
        if ($bits === null) {
            return $a === $b;
        }

        $bits = (int)$bits;
        $bytes = intdiv($bits, 8);
        if (substr($a, 0, $bytes) !== substr($b, 0, $bytes)) {
            return false;
        }
        $rest = $bits % 8;
        if ($rest === 0) {
            return true;
        }
        $mask = (0xFF << (8 - $rest)) & 0xFF;

        return (ord($a[$bytes]) & $mask) === (ord($b[$bytes]) & $mask);
    }
}
