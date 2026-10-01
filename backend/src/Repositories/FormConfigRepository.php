<?php
declare(strict_types=1);

namespace App\Repositories;

use App\Config\Database;
use App\Config\TenantContext;
use App\Forms\ConflictException;
use App\Forms\Identifiers;
use App\Forms\NotFoundException;
use mysqli;

/**
 * Read/write access to form_configs (the live configuration of a form), scoped to the current tenant.
 *
 * Every query filters by tenant_id from TenantContext. In all-tenants mode TenantContext::getTenantId()
 * throws, so a platform admin must pick a tenant before editing — there is no "write to all" path.
 *
 * The "version token" of a config is the SHA-256 of the stored config_json string. Callers that
 * read-modify-write pass the token back as $expectedSha; a mismatch means someone else saved in between.
 * Wrap read-modify-write in a transaction and call find(..., forUpdate: true) to make that airtight.
 */
class FormConfigRepository
{
    private mysqli $db;

    public function __construct(?mysqli $db = null)
    {
        $this->db = $db ?? Database::getConnection();
    }

    /**
     * @return array{config: array<string,mixed>, json: string, sha256: string}|null  json = the stored string (hash input)
     */
    public function find(string $formKey, bool $forUpdate = false): ?array
    {
        $tenantId = TenantContext::getTenantId();
        $sql = 'SELECT config_json FROM form_configs WHERE tenant_id = ? AND form_key = ? LIMIT 1'
             . ($forUpdate ? ' FOR UPDATE' : '');
        $stmt = $this->db->prepare($sql);
        $stmt->bind_param('is', $tenantId, $formKey);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        if (!$row) {
            return null;
        }

        $json   = (string)$row['config_json'];
        $config = json_decode($json, true);

        return [
            'config' => is_array($config) ? $config : [],
            'json'   => $json,
            'sha256' => hash('sha256', $json),
        ];
    }

    /**
     * @return list<string>
     */
    public function listKeys(): array
    {
        $tenantId = TenantContext::getTenantId();
        $stmt = $this->db->prepare('SELECT form_key FROM form_configs WHERE tenant_id = ? ORDER BY form_key ASC');
        $stmt->bind_param('i', $tenantId);
        $stmt->execute();

        $keys = [];
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $keys[] = (string)$row['form_key'];
        }
        return $keys;
    }

    /**
     * Create a form config.
     *
     * @param array<string,mixed> $config
     * @return string sha256 of the stored JSON
     * @throws ConflictException if the form key already exists for this tenant
     */
    public function insert(string $formKey, array $config): string
    {
        $this->assertFormKey($formKey);
        $tenantId = TenantContext::getTenantId();
        $json     = self::encode($config);

        $stmt = $this->db->prepare(
            'INSERT INTO form_configs (tenant_id, form_key, config_json) VALUES (?, ?, ?)'
        );
        $stmt->bind_param('iss', $tenantId, $formKey, $json);
        try {
            $stmt->execute();
        } catch (\mysqli_sql_exception $e) {
            if ($e->getCode() === 1062) { // duplicate key
                throw new ConflictException("Formular '{$formKey}' existiert bereits");
            }
            throw $e;
        }

        return hash('sha256', $json);
    }

    /**
     * Replace a form config.
     *
     * @param array<string,mixed> $config
     * @param string|null $expectedSha token from find(); null skips the check
     * @return string new sha256
     * @throws NotFoundException  no such form for this tenant
     * @throws ConflictException  stored config differs from $expectedSha
     */
    public function update(string $formKey, array $config, ?string $expectedSha): string
    {
        $current = $this->find($formKey, forUpdate: true);
        if ($current === null) {
            throw new NotFoundException("Formular '{$formKey}' nicht gefunden");
        }
        if ($expectedSha !== null && !hash_equals($current['sha256'], $expectedSha)) {
            throw new ConflictException("Formular '{$formKey}' wurde zwischenzeitlich geändert");
        }

        $tenantId = TenantContext::getTenantId();
        $json     = self::encode($config);
        $stmt = $this->db->prepare('UPDATE form_configs SET config_json = ? WHERE tenant_id = ? AND form_key = ?');
        $stmt->bind_param('sis', $json, $tenantId, $formKey);
        $stmt->execute();

        return hash('sha256', $json);
    }

    public function delete(string $formKey): bool
    {
        $tenantId = TenantContext::getTenantId();
        $stmt = $this->db->prepare('DELETE FROM form_configs WHERE tenant_id = ? AND form_key = ?');
        $stmt->bind_param('is', $tenantId, $formKey);
        $stmt->execute();
        return $stmt->affected_rows > 0;
    }

    /**
     * Number of stored submissions for a form, including those in the trash.
     * Forms with submissions must not be deleted: the entries reference the form key.
     */
    public function countSubmissions(string $formKey): int
    {
        $tenantId = TenantContext::getTenantId();
        $stmt = $this->db->prepare('SELECT COUNT(*) AS cnt FROM anmeldungen WHERE tenant_id = ? AND formular = ?');
        $stmt->bind_param('is', $tenantId, $formKey);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        return (int)($row['cnt'] ?? 0);
    }

    /** @param array<string,mixed> $config */
    public static function encode(array $config): string
    {
        return json_encode($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    private function assertFormKey(string $formKey): void
    {
        if (!Identifiers::isValidFormKey($formKey)) {
            throw new \InvalidArgumentException('Ungültiger Formular-Schlüssel');
        }
    }
}
