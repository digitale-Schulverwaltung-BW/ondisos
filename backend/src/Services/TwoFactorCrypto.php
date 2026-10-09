<?php
// src/Services/TwoFactorCrypto.php
// Encryption of TOTP secrets at rest (libsodium secretbox)

declare(strict_types=1);

namespace App\Services;

use App\Config\EnvLoader;

/**
 * Encrypts TOTP secrets with a key from the environment (TWO_FACTOR_KEY, 64 hex characters).
 *
 * Without encryption a database dump would also be a dump of all second factors. The key lives in
 * the .env, not in the database. There is no key rotation in 3.1.2: replacing the key invalidates
 * all enrolled authenticators (reset with reset-2fa.php).
 */
final class TwoFactorCrypto
{
    public function __construct(private readonly string $key)
    {
        if (strlen($key) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
            throw new \InvalidArgumentException('Two-factor key must be 32 bytes.');
        }
    }

    /**
     * @throws \RuntimeException when TWO_FACTOR_KEY is missing or not 64 hex characters
     */
    public static function fromEnvironment(): self
    {
        $hex = trim((string)EnvLoader::get('TWO_FACTOR_KEY', ''));
        if ($hex === '') {
            throw new \RuntimeException('TWO_FACTOR_KEY is not set. Generate one: openssl rand -hex 32');
        }
        if (!preg_match('/^[0-9a-fA-F]{64}$/', $hex)) {
            throw new \RuntimeException('TWO_FACTOR_KEY must be 64 hex characters. Generate one: openssl rand -hex 32');
        }

        return new self((string)hex2bin($hex));
    }

    public static function isConfigured(): bool
    {
        try {
            self::fromEnvironment();

            return true;
        } catch (\RuntimeException) {
            return false;
        }
    }

    public function encrypt(string $plain): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

        return base64_encode($nonce . sodium_crypto_secretbox($plain, $nonce, $this->key));
    }

    /**
     * @throws \RuntimeException when the value is damaged, manipulated or encrypted with another key
     */
    public function decrypt(string $encoded): string
    {
        $raw = base64_decode($encoded, true);
        if ($raw === false || strlen($raw) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            throw new \RuntimeException('Encrypted value is malformed.');
        }
        $nonce = substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $plain = sodium_crypto_secretbox_open(substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), $nonce, $this->key);
        if ($plain === false) {
            throw new \RuntimeException('Encrypted value could not be decrypted (wrong key or manipulated).');
        }

        return $plain;
    }
}
