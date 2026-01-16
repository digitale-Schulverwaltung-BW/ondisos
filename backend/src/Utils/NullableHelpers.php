<?php
// src/Utils/NullableHelpers.php

declare(strict_types=1);

namespace App\Utils;

class NullableHelpers
{
    /**
     * Safe string display with fallback
     */
    public static function displayString(?string $value, string $fallback = '-'): string
    {
        return $value !== null && trim($value) !== '' ? $value : $fallback;
    }

    /**
     * Safe HTML display (escaped)
     */
    public static function displayHtml(?string $value, string $fallback = '-'): string
    {
        return htmlspecialchars(self::displayString($value, $fallback), ENT_QUOTES, 'UTF-8');
    }

    /**
     * Safe email display with obfuscation option
     */
    public static function displayEmail(?string $email, bool $obfuscate = false): string
    {
        if ($email === null || trim($email) === '') {
            return '-';
        }

        if (!$obfuscate) {
            return $email;
        }

        // Obfuscate: john.doe@example.com → j***e@example.com
        [$local, $domain] = explode('@', $email, 2);
        $localLen = strlen($local);
        
        if ($localLen <= 2) {
            $obfuscated = str_repeat('*', $localLen);
        } else {
            $obfuscated = $local[0] . str_repeat('*', $localLen - 2) . $local[$localLen - 1];
        }

        return $obfuscated . '@' . $domain;
    }

    /**
     * Check if array/data is empty or null
     */
    public static function isEmptyData(?array $data): bool
    {
        return $data === null || empty($data);
    }

    /**
     * Safe JSON decode with fallback
     */
    public static function jsonDecodeOrNull(?string $json): ?array
    {
        if ($json === null || trim($json) === '') {
            return null;
        }

        $decoded = json_decode($json, true);
        return is_array($decoded) ? $decoded : null;
    }

    /**
     * Assert not null or throw
     */
    public static function assertNotNull(mixed $value, string $message = 'Value cannot be null'): mixed
    {
        if ($value === null) {
            throw new \InvalidArgumentException($message);
        }
        return $value;
    }
}