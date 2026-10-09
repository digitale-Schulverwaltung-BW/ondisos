<?php
declare(strict_types=1);

namespace App\Repositories;

use App\Config\Database;
use mysqli;

/**
 * TwoFactorRepository — second factors (TOTP) and recovery codes of admins.
 *
 * Addressed by an admin key, not by tenant: `tenant:<tenant_admins.id>` for tenant admins and
 * `platform:<ADMIN_USERNAME>` for the platform admin from the .env (see AdminKey).
 * Rows are looked up by exactly this key, so there is no listing and no tenant filter to forget.
 * The TOTP secret is stored encrypted (TwoFactorCrypto); this class never sees the plain value.
 */
class TwoFactorRepository
{
    private mysqli $db;

    public function __construct(?mysqli $db = null)
    {
        $this->db = $db ?? Database::getConnection();
    }

    /**
     * @return array{totp_secret_enc: ?string, totp_confirmed_at: ?string, last_totp_step: ?int, grace_logins_used: int}|null
     */
    public function find(string $adminKey): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT totp_secret_enc, totp_confirmed_at, last_totp_step, grace_logins_used
             FROM admin_two_factor WHERE admin_key = ? LIMIT 1'
        );
        $stmt->bind_param('s', $adminKey);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($row === null) {
            return null;
        }

        return [
            'totp_secret_enc' => $row['totp_secret_enc'] !== null ? (string)$row['totp_secret_enc'] : null,
            'totp_confirmed_at' => $row['totp_confirmed_at'] !== null ? (string)$row['totp_confirmed_at'] : null,
            'last_totp_step' => $row['last_totp_step'] !== null ? (int)$row['last_totp_step'] : null,
            'grace_logins_used' => (int)$row['grace_logins_used'],
        ];
    }

    public function hasFactor(string $adminKey): bool
    {
        $row = $this->find($adminKey);

        return $row !== null && $row['totp_secret_enc'] !== null && $row['totp_confirmed_at'] !== null;
    }

    public function graceLoginsUsed(string $adminKey): int
    {
        return $this->find($adminKey)['grace_logins_used'] ?? 0;
    }

    /** Counts one login without second factor (only call after a successful password login). */
    public function incrementGraceLogins(string $adminKey): void
    {
        $stmt = $this->db->prepare(
            'INSERT INTO admin_two_factor (admin_key, grace_logins_used) VALUES (?, 1)
             ON DUPLICATE KEY UPDATE grace_logins_used = grace_logins_used + 1'
        );
        $stmt->bind_param('s', $adminKey);
        $stmt->execute();
        $stmt->close();
    }

    /**
     * Stores the confirmed factor together with the recovery codes (all or nothing).
     * An existing factor of the same admin is replaced.
     *
     * @param list<string> $recoveryCodeHashes
     */
    public function enable(string $adminKey, string $encryptedSecret, int $lastStep, array $recoveryCodeHashes): void
    {
        $this->db->begin_transaction();
        try {
            $stmt = $this->db->prepare(
                'INSERT INTO admin_two_factor (admin_key, totp_secret_enc, totp_confirmed_at, last_totp_step)
                 VALUES (?, ?, NOW(), ?)
                 ON DUPLICATE KEY UPDATE totp_secret_enc = VALUES(totp_secret_enc),
                     totp_confirmed_at = VALUES(totp_confirmed_at), last_totp_step = VALUES(last_totp_step)'
            );
            $stmt->bind_param('ssi', $adminKey, $encryptedSecret, $lastStep);
            $stmt->execute();
            $stmt->close();
            $this->replaceRecoveryCodes($adminKey, $recoveryCodeHashes);
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollback();
            throw $e;
        }
    }

    /**
     * Records an accepted time step. False if the step is not newer than the stored one
     * (replay, or a parallel request that was faster): the caller must then reject the login.
     */
    public function acceptTotpStep(string $adminKey, int $step): bool
    {
        $stmt = $this->db->prepare(
            'UPDATE admin_two_factor SET last_totp_step = ?
             WHERE admin_key = ? AND (last_totp_step IS NULL OR last_totp_step < ?)'
        );
        $stmt->bind_param('isi', $step, $adminKey, $step);
        $stmt->execute();
        $accepted = $stmt->affected_rows === 1;
        $stmt->close();

        return $accepted;
    }

    /**
     * @param list<string> $hashes
     */
    public function replaceRecoveryCodes(string $adminKey, array $hashes): void
    {
        $del = $this->db->prepare('DELETE FROM admin_recovery_codes WHERE admin_key = ?');
        $del->bind_param('s', $adminKey);
        $del->execute();
        $del->close();

        $ins = $this->db->prepare('INSERT INTO admin_recovery_codes (admin_key, code_hash) VALUES (?, ?)');
        foreach ($hashes as $hash) {
            $ins->bind_param('ss', $adminKey, $hash);
            $ins->execute();
        }
        $ins->close();
    }

    /** Uses a recovery code once. False if unknown or already used. */
    public function consumeRecoveryCode(string $adminKey, string $hash): bool
    {
        $stmt = $this->db->prepare(
            'UPDATE admin_recovery_codes SET used_at = NOW()
             WHERE admin_key = ? AND code_hash = ? AND used_at IS NULL LIMIT 1'
        );
        $stmt->bind_param('ss', $adminKey, $hash);
        $stmt->execute();
        $used = $stmt->affected_rows === 1;
        $stmt->close();

        return $used;
    }

    public function remainingRecoveryCodes(string $adminKey): int
    {
        $stmt = $this->db->prepare('SELECT COUNT(*) AS cnt FROM admin_recovery_codes WHERE admin_key = ? AND used_at IS NULL');
        $stmt->bind_param('s', $adminKey);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return (int)($row['cnt'] ?? 0);
    }

    /**
     * Removes the factor and the recovery codes. With $restartGrace the admin gets the grace
     * logins again (reset by an operator), otherwise the counter is kept (admin removed it themself).
     */
    public function remove(string $adminKey, bool $restartGrace): void
    {
        $this->db->begin_transaction();
        try {
            $del = $this->db->prepare('DELETE FROM admin_recovery_codes WHERE admin_key = ?');
            $del->bind_param('s', $adminKey);
            $del->execute();
            $del->close();

            $sql = $restartGrace
                ? 'UPDATE admin_two_factor SET totp_secret_enc = NULL, totp_confirmed_at = NULL, last_totp_step = NULL, grace_logins_used = 0 WHERE admin_key = ?'
                : 'UPDATE admin_two_factor SET totp_secret_enc = NULL, totp_confirmed_at = NULL, last_totp_step = NULL WHERE admin_key = ?';
            $upd = $this->db->prepare($sql);
            $upd->bind_param('s', $adminKey);
            $upd->execute();
            $upd->close();
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollback();
            throw $e;
        }
    }
}
