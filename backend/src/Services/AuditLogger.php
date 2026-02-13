<?php
// src/Services/AuditLogger.php

declare(strict_types=1);

namespace App\Services;

/**
 * Lightweight file-based audit logger.
 *
 * Writes structured JSON-Lines entries to logs/audit.log.
 * Tracks security-relevant events for GDPR compliance.
 *
 * Format (one JSON object per line):
 *   {"ts":"2026-02-13T10:30:00+01:00","event":"login_success","user":"admin","ip":"192.168.1.1","details":{}}
 */
class AuditLogger
{
    private const LOG_FILE = __DIR__ . '/../../logs/audit.log';

    // =========================================================================
    // Public convenience methods
    // =========================================================================

    public static function loginSuccess(string $username): void
    {
        self::log('login_success', ['username' => $username]);
    }

    public static function loginFailed(string $username): void
    {
        self::log('login_failed', ['username' => $username]);
    }

    public static function logout(string $username): void
    {
        self::log('logout', ['username' => $username]);
    }

    public static function statusChanged(int $id, string $newStatus): void
    {
        self::log('status_changed', ['id' => $id, 'new_status' => $newStatus]);
    }

    public static function bulkAction(string $action, array $ids): void
    {
        self::log('bulk_' . $action, ['ids' => $ids, 'count' => count($ids)]);
    }

    public static function uploadSuccess(int $anmeldungId, string $filename): void
    {
        self::log('upload_success', ['anmeldung_id' => $anmeldungId, 'file' => $filename]);
    }

    public static function uploadRejected(int $anmeldungId, string $filename, string $reason): void
    {
        self::log('upload_rejected', ['anmeldung_id' => $anmeldungId, 'file' => $filename, 'reason' => $reason]);
    }

    public static function virusFound(int $anmeldungId, string $filename, string $virusName): void
    {
        self::log('virus_found', ['anmeldung_id' => $anmeldungId, 'file' => $filename, 'virus' => $virusName]);
    }

    public static function exportRun(string $formular, int $count): void
    {
        self::log('export', ['formular' => $formular ?: 'all', 'count' => $count]);
    }

    // =========================================================================
    // Log rotation
    // =========================================================================

    /**
     * Remove log entries older than $retentionDays.
     *
     * Uses an atomic write (temp file + rename) so the log file is never
     * left in a partial state. Returns the number of lines removed.
     *
     * @param int $retentionDays 0 = disabled (no rotation)
     */
    public static function rotate(int $retentionDays): int
    {
        if ($retentionDays <= 0 || !file_exists(self::LOG_FILE)) {
            return 0;
        }

        $cutoff  = (new \DateTimeImmutable())->modify("-{$retentionDays} days");
        $removed = 0;
        $tmpFile = self::LOG_FILE . '.tmp';

        $fh = fopen($tmpFile, 'w');
        if ($fh === false) {
            return 0; // can't write temp file — skip silently
        }

        foreach (self::readLines() as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            $keep = true;
            $data = json_decode($line, true);

            if (is_array($data) && isset($data['ts'])) {
                try {
                    $keep = new \DateTimeImmutable($data['ts']) >= $cutoff;
                } catch (\Throwable) {
                    // unparseable timestamp → keep to be safe
                }
            }

            if ($keep) {
                fwrite($fh, $line . "\n");
            } else {
                $removed++;
            }
        }

        fclose($fh);

        if ($removed > 0) {
            rename($tmpFile, self::LOG_FILE);
        } else {
            unlink($tmpFile); // nothing removed — discard temp file
        }

        return $removed;
    }

    /**
     * Yield lines from the log file without loading the whole file into memory.
     *
     * @return \Generator<string>
     */
    private static function readLines(): \Generator
    {
        $fh = @fopen(self::LOG_FILE, 'r');
        if ($fh === false) {
            return;
        }
        while (($line = fgets($fh)) !== false) {
            yield $line;
        }
        fclose($fh);
    }

    // =========================================================================
    // Core logging
    // =========================================================================

    private static function log(string $event, array $details = []): void
    {
        $logDir = dirname(self::LOG_FILE);
        if (!is_dir($logDir)) {
            mkdir($logDir, 0755, true);
        }

        $entry = json_encode([
            'ts'      => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
            'event'   => $event,
            'user'    => self::getUser(),
            'ip'      => self::getIp(),
            'details' => $details,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if ($entry === false) {
            return; // json_encode failed — don't crash the application
        }

        file_put_contents(self::LOG_FILE, $entry . "\n", FILE_APPEND | LOCK_EX);
    }

    private static function getUser(): ?string
    {
        return $_SESSION['admin_username'] ?? null;
    }

    private static function getIp(): string
    {
        // Support reverse proxy / Docker setup
        $forwarded = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '';
        if ($forwarded !== '') {
            // X-Forwarded-For can be a comma-separated list; first entry is client IP
            return trim(explode(',', $forwarded)[0]);
        }

        return $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    }
}
