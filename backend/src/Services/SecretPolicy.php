<?php
declare(strict_types=1);

namespace App\Services;

use App\Config\EnvLoader;

/**
 * SecretPolicy — rejects secrets that are publicly known (API and PDF tokens).
 *
 * The repository ships placeholder secrets (database/schema.sql seeds tenant 1,
 * .env.example / docker-compose.yml / the Docker entrypoint provide dev defaults).
 * Anyone who can read the repository could sign valid requests or mint PDF download
 * tokens against an installation that still uses one of them, so such secrets must
 * never authenticate anything in production.
 *
 *  - Placeholders (empty, CHANGE_ME_IN_PRODUCTION): never acceptable.
 *  - Dev defaults: acceptable outside production so a fresh checkout works
 *    out of the box, rejected when APP_ENV=production.
 */
final class SecretPolicy
{
    /** Seeded by database/schema.sql; meaningless by design. */
    public const PLACEHOLDER = 'CHANGE_ME_IN_PRODUCTION';

    /** Shipped in .env.example / docker-compose.yml / backend/docker/entrypoint.sh for local development. */
    private const DEV_DEFAULTS = [
        'dev-api-key-replace-in-production',
        'dev-secret-key-replace-in-production-min-32-chars',
        'change-me-min-32-characters',
    ];

    public static function isPlaceholder(string $secret): bool
    {
        return trim($secret) === '' || $secret === self::PLACEHOLDER;
    }

    public static function isDevDefault(string $secret): bool
    {
        // Older .env.example files had a trailing "  # Min 32 chars!", which EnvLoader keeps as part of the value.
        $withoutComment = (string) preg_replace('/\s+#.*$/', '', trim($secret));

        return in_array($withoutComment, self::DEV_DEFAULTS, true);
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
