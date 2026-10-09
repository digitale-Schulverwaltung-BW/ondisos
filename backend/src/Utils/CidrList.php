<?php
// src/Utils/CidrList.php
// List of IPs and CIDR ranges (IPv4 and IPv6) with a membership test

declare(strict_types=1);

namespace App\Utils;

/**
 * Pure logic, independent of the environment: used for TRUSTED_PROXIES and the admin network allowlist.
 *
 * Entries are separated by commas, semicolons or line breaks. Invalid entries are not
 * dropped silently: they are collected in invalid() so that forms and checks can report them.
 * An empty list contains nothing; whether "empty" means "no restriction" is up to the caller.
 */
final class CidrList
{
    /**
     * @param list<string> $entries normalized entries ("1.2.3.4", "10.0.0.0/8", "2001:db8::/32")
     * @param list<string> $invalid entries as written that could not be parsed
     */
    private function __construct(
        private readonly array $entries,
        private readonly array $invalid,
    ) {
    }

    public static function parse(string $value): self
    {
        $entries = [];
        $invalid = [];
        foreach (preg_split('/[\s,;]+/', $value) ?: [] as $item) {
            if ($item === '') {
                continue;
            }
            $entry = self::parseEntry($item);
            if ($entry === null) {
                $invalid[] = $item;
            } else {
                $entries[] = $entry;
            }
        }

        return new self(array_values(array_unique($entries)), $invalid);
    }

    /**
     * @return list<string>
     */
    public function entries(): array
    {
        return $this->entries;
    }

    /**
     * @return list<string>
     */
    public function invalid(): array
    {
        return $this->invalid;
    }

    public function isEmpty(): bool
    {
        return $this->entries === [];
    }

    /**
     * Whether the IP lies in one of the entries; false for an invalid IP.
     */
    public function contains(string $ip): bool
    {
        $normalized = self::normalize($ip);
        if ($normalized === null) {
            return false;
        }
        foreach ($this->entries as $entry) {
            if (self::matches($normalized, $entry)) {
                return true;
            }
        }

        return false;
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
        if (self::isMapped($bin)) {
            $bin = substr($bin, 12);
        }

        return inet_ntop($bin) ?: null;
    }

    private static function parseEntry(string $item): ?string
    {
        $parts = explode('/', $item, 2);
        $bin = filter_var($parts[0], FILTER_VALIDATE_IP) !== false ? @inet_pton($parts[0]) : false;
        if ($bin === false) {
            return null;
        }
        $mapped = self::isMapped($bin);
        $ip = self::normalize($parts[0]);
        if ($ip === null) {
            return null;
        }
        if (count($parts) === 1) {
            return $ip;
        }

        if (!ctype_digit($parts[1])) {
            return null;
        }
        $bits = (int)$parts[1];
        if ($mapped) {
            // ::ffff:a.b.c.d/N is an IPv4 range of N-96 bits; shorter prefixes would span IPv6 space
            $bits -= 96;
            if ($bits < 0) {
                return null;
            }
        }
        $max = str_contains($ip, ':') ? 128 : 32;
        if ($bits > $max) {
            return null;
        }

        return $ip . '/' . $bits;
    }

    private static function isMapped(string $bin): bool
    {
        return strlen($bin) === 16 && str_starts_with($bin, str_repeat("\0", 10) . "\xff\xff");
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
