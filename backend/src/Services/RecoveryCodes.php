<?php
// src/Services/RecoveryCodes.php
// One-time recovery codes for the second factor

declare(strict_types=1);

namespace App\Services;

/**
 * Codes look like "k7m2-9xqp-4rtb" (12 characters from a 31-character alphabet without look-alikes,
 * about 59 bits). They are random and long, so a plain SHA-256 is enough for storage;
 * guessing is limited by the throttle on the code input.
 */
final class RecoveryCodes
{
    public const COUNT = 10;
    private const ALPHABET = 'abcdefghjkmnpqrstuvwxyz23456789';

    /**
     * @return list<string>
     */
    public static function generate(int $count = self::COUNT): array
    {
        $codes = [];
        while (count($codes) < $count) {
            $raw = '';
            for ($i = 0; $i < 12; $i++) {
                $raw .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
            }
            $codes[$raw] = implode('-', str_split($raw, 4));
        }

        return array_values($codes);
    }

    /** Hash for storage and comparison; case, dashes and spaces are ignored. */
    public static function hash(string $code): string
    {
        return hash('sha256', self::normalize($code));
    }

    public static function normalize(string $code): string
    {
        return preg_replace('/[^a-z0-9]/', '', strtolower($code)) ?? '';
    }

    /** Whether the input has the shape of a recovery code (not a 6-digit TOTP code). */
    public static function looksLikeCode(string $input): bool
    {
        return strlen(self::normalize($input)) === 12;
    }
}
