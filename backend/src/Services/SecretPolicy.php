<?php
declare(strict_types=1);

namespace App\Services;

use App\Config\EnvLoader;

/**
 * SecretPolicy — rejects API secrets that are publicly known.
 *
 * The repository ships placeholder secrets (database/schema.sql seeds tenant 1,
 * .env.example / docker-compose.yml provide a dev default). Anyone who can read
 * the repository could sign valid requests against an installation that still
 * uses one of them, so such secrets must never authenticate anything.
 *
 *  - Placeholders (empty, CHANGE_ME_IN_PRODUCTION): never acceptable.
 *  - Dev defaults: acceptable outside production so a fresh checkout works
 *    out of the box, rejected when APP_ENV=production.
 */
final class SecretPolicy
{
    /** Seeded by database/schema.sql; meaningless by design. */
    public const PLACEHOLDER = 'CHANGE_ME_IN_PRODUCTION';

    /** Shipped in .env.example / docker-compose.yml for local development. */
    private const DEV_DEFAULTS = [
        'dev-api-key-replace-in-production',
    ];

    public static function isPlaceholder(string $secret): bool
    {
        return trim($secret) === '' || $secret === self::PLACEHOLDER;
    }

    public static function isDevDefault(string $secret): bool
    {
        return in_array($secret, self::DEV_DEFAULTS, true);
    }

    public static function isProduction(): bool
    {
        return strtolower((string) EnvLoader::get('APP_ENV', 'production')) === 'production';
    }

    /**
     * @param bool|null $production Override environment detection (tests, CLI tools)
     */
    public static function isAcceptable(string $secret, ?bool $production = null): bool
    {
        if (self::isPlaceholder($secret)) {
            return false;
        }

        return !(self::isDevDefault($secret) && ($production ?? self::isProduction()));
    }
}
