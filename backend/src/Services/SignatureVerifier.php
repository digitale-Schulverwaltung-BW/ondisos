<?php
// src/Services/SignatureVerifier.php

declare(strict_types=1);

namespace App\Services;

/**
 * Prüft die Signatur einer API-Anfrage des Frontends (submit, upload, forms).
 *
 * Zwei Formate:
 *   v2 (Header X-Timestamp + X-Nonce + X-Signature): HMAC über Zweck, Zeit, Nonce und den Inhalt
 *      (bei Uploads einschließlich SHA-256 der Datei). Die Anfrage muss frisch sein (±maxSkew) und
 *      ihre Nonce darf nicht schon verwendet worden sein (Replay-Schutz).
 *   v1 (nur X-Signature): das bisherige Format ohne Zeit und Nonce.
 *
 * Modus "compat" (Standard) akzeptiert beide, "strict" nur v2. Ein v2-Header mit ungültiger Signatur
 * wird nie auf v1 zurückgestuft.
 */
class SignatureVerifier
{
    public const MODE_COMPAT = 'compat';
    public const MODE_STRICT = 'strict';

    /**
     * Standard, wenn API_SIGNATURE_MODE nicht gesetzt ist: seit 3.1.3 "strict" (nur Format 2). Die Umstellungsphase
     * mit "compat" (3.1.2) ist vorbei; wer noch Frontends mit Format 1 betreibt, setzt "compat" ausdrücklich.
     */
    public const DEFAULT_MODE = self::MODE_STRICT;

    /** Gültigkeit der Nonce: Zeitstempel darf ±maxSkew abweichen, also bis zu 2 × maxSkew merken. */
    private const NONCE_TTL_FACTOR = 2;

    public function __construct(
        private readonly HmacValidator $hmac,
        private readonly ?ReplayGuard $replayGuard,
        private readonly string $mode = self::DEFAULT_MODE,
        private readonly int $maxSkew = 300,
        private readonly int $tenantId = 0,
        private readonly ?\Closure $clock = null,
    ) {
    }

    public static function fromEnv(string $secret, int $tenantId): self
    {
        $mode = strtolower((string)($_ENV['API_SIGNATURE_MODE'] ?? self::DEFAULT_MODE));
        $mode = in_array($mode, [self::MODE_COMPAT, self::MODE_STRICT], true) ? $mode : self::DEFAULT_MODE;
        $skew = max(30, (int)($_ENV['API_SIGNATURE_MAX_SKEW'] ?? 300));

        return new self(
            new HmacValidator($secret),
            new ReplayGuard(__DIR__ . '/../../cache/replay', $skew * self::NONCE_TTL_FACTOR),
            $mode,
            $skew,
            $tenantId
        );
    }

    /** @param array<string,mixed> $server */
    public function verifySubmit(string $body, array $server): array
    {
        return $this->verify(
            'submit',
            hash('sha256', $body),
            fn(string $sig): bool => $this->hmac->validate($body, $sig),
            $server,
            true
        );
    }

    /**
     * @param string|null $tmpPath temporäre Datei des Uploads (null, wenn keine angekommen ist)
     * @param array<string,mixed> $server
     */
    public function verifyUpload(string $anmeldungId, string $fieldname, string $filename, ?string $tmpPath, array $server): array
    {
        $fileHash = ($tmpPath !== null && is_file($tmpPath)) ? (string)hash_file('sha256', $tmpPath) : '';

        return $this->verify(
            'upload',
            self::uploadPayload($anmeldungId, $fieldname, $filename, $fileHash),
            fn(string $sig): bool => $this->hmac->validateUploadSignature($anmeldungId, $fieldname, $filename, $sig),
            $server,
            true
        );
    }

    /** @param array<string,mixed> $server */
    public function verifyForms(string $slug, array $server): array
    {
        // Lesender Aufruf: Zeitfenster genügt, keine Nonce-Verwaltung
        return $this->verify(
            'forms',
            $slug,
            fn(string $sig): bool => $this->hmac->validateMessage('forms:' . $slug, $sig),
            $server,
            false
        );
    }

    public static function uploadPayload(string $anmeldungId, string $fieldname, string $filename, string $fileSha256): string
    {
        return $anmeldungId . "\n" . $fieldname . "\n" . $filename . "\n" . $fileSha256;
    }

    /**
     * @param \Closure(string): bool $legacyCheck
     * @param array<string,mixed> $server
     * @return array{ok: bool, version: int, reason: string}
     */
    private function verify(string $purpose, string $v2Payload, \Closure $legacyCheck, array $server, bool $checkReplay): array
    {
        $signature = (string)($server['HTTP_X_SIGNATURE'] ?? '');
        $timestamp = (string)($server['HTTP_X_TIMESTAMP'] ?? '');
        $nonce     = (string)($server['HTTP_X_NONCE'] ?? '');

        if ($timestamp !== '' || $nonce !== '') {
            return $this->verifyV2($purpose, $v2Payload, $signature, $timestamp, $nonce, $checkReplay);
        }

        if ($this->mode === self::MODE_STRICT) {
            return $this->fail($purpose, 'legacy_not_allowed');
        }
        if (!$legacyCheck($signature)) {
            return $this->fail($purpose, 'bad_signature');
        }

        AuditLogger::apiLegacySignature($purpose, $this->tenantId);

        return ['ok' => true, 'version' => 1, 'reason' => ''];
    }

    /** @return array{ok: bool, version: int, reason: string} */
    private function verifyV2(string $purpose, string $payload, string $signature, string $timestamp, string $nonce, bool $checkReplay): array
    {
        if (preg_match('/^\d{9,12}$/', $timestamp) !== 1 || preg_match('/^[a-f0-9]{16,64}$/', $nonce) !== 1) {
            return $this->fail($purpose, 'malformed');
        }
        // Erst die Signatur: Zeit- und Replay-Antworten sollen Unbefugten nichts verraten
        if (!$this->hmac->validateV2($purpose, $payload, $timestamp, $nonce, $signature)) {
            return $this->fail($purpose, 'bad_signature');
        }
        if (abs($this->now() - (int)$timestamp) > $this->maxSkew) {
            return $this->fail($purpose, 'timestamp_skew');
        }
        if ($checkReplay && $this->replayGuard !== null && !$this->replayGuard->firstUse($this->tenantId . '|' . $nonce)) {
            return $this->fail($purpose, 'replay');
        }

        return ['ok' => true, 'version' => 2, 'reason' => ''];
    }

    /** @return array{ok: bool, version: int, reason: string} */
    private function fail(string $purpose, string $reason): array
    {
        // Ohne Secret und Signatur. "timestamp_skew" heißt meist: Uhren von Frontend- und Backend-Server gehen unterschiedlich
        error_log(sprintf('API signature rejected (%s, tenant %d): %s', $purpose, $this->tenantId, $reason));

        return ['ok' => false, 'version' => 0, 'reason' => $reason];
    }

    private function now(): int
    {
        return $this->clock !== null ? (int)($this->clock)() : time();
    }
}
