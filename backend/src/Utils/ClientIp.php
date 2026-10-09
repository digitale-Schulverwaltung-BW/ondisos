<?php
// src/Utils/ClientIp.php
// Client IP detection with optional trust in reverse proxies (TRUSTED_PROXIES)

declare(strict_types=1);

namespace App\Utils;

use App\Config\EnvLoader;

/**
 * Determines the client IP for rate limiting, audit log and login records.
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
            self::parseTrustedProxies((string)EnvLoader::get('TRUSTED_PROXIES', ''))
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
        $trusted = CidrList::parse(implode(',', $trustedProxies));
        if ($trusted->isEmpty() || !is_string($forwarded) || trim($forwarded) === ''
            || !$trusted->contains($remoteIp)) {
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
            if (!$trusted->contains($ip)) {
                break;
            }
        }

        return $client;
    }

    /**
     * Parses a list of IPs/CIDR ranges; invalid entries are dropped (see CidrList::parse() to report them).
     *
     * @return list<string> normalized entries ("1.2.3.4", "10.0.0.0/8", "2001:db8::/32")
     */
    public static function parseTrustedProxies(string $value): array
    {
        return CidrList::parse($value)->entries();
    }

    /**
     * Valid IP in canonical form (IPv4-mapped IPv6 as IPv4), otherwise null.
     */
    public static function normalize(string $ip): ?string
    {
        return CidrList::normalize($ip);
    }
}
