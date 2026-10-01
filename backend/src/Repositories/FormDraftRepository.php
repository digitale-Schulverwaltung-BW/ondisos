<?php
declare(strict_types=1);

namespace App\Repositories;

use App\Config\Database;
use App\Config\TenantContext;
use App\Forms\Identifiers;
use mysqli;

/**
 * The survey draft of a form (at most one per tenant and form), scoped to the current tenant.
 *
 * A draft is never delivered by the public API: only the backend session may read it.
 */
class FormDraftRepository
{
    private mysqli $db;

    public function __construct(?mysqli $db = null)
    {
        $this->db = $db ?? Database::getConnection();
    }

    /**
     * @return array{survey_json: string, based_on_sha: ?string, updated_at: ?string, updated_by: ?string}|null
     */
    public function find(string $formKey, bool $forUpdate = false): ?array
    {
        $tenantId = TenantContext::getTenantId();
        $sql = 'SELECT survey_json, based_on_sha, updated_at, updated_by FROM form_drafts
                WHERE tenant_id = ? AND form_key = ? LIMIT 1' . ($forUpdate ? ' FOR UPDATE' : '');
        $stmt = $this->db->prepare($sql);
        $stmt->bind_param('is', $tenantId, $formKey);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();

        return $row ?: null;
    }

    /**
     * Insert or replace the draft of a form.
     *
     * @param string|null $basedOnSha hash of the live survey the draft was started from (null = none was live)
     */
    public function save(string $formKey, string $surveyJson, ?string $basedOnSha, ?string $updatedBy): void
    {
        if (!Identifiers::isValidFormKey($formKey)) {
            throw new \InvalidArgumentException('Ungültiger Formular-Schlüssel');
        }
        $tenantId = TenantContext::getTenantId();

        $stmt = $this->db->prepare(
            'INSERT INTO form_drafts (tenant_id, form_key, survey_json, based_on_sha, updated_by)
             VALUES (?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE survey_json = VALUES(survey_json), based_on_sha = VALUES(based_on_sha),
                                     updated_by = VALUES(updated_by)'
        );
        $stmt->bind_param('issss', $tenantId, $formKey, $surveyJson, $basedOnSha, $updatedBy);
        $stmt->execute();
    }

    public function delete(string $formKey): bool
    {
        $tenantId = TenantContext::getTenantId();
        $stmt = $this->db->prepare('DELETE FROM form_drafts WHERE tenant_id = ? AND form_key = ?');
        $stmt->bind_param('is', $tenantId, $formKey);
        $stmt->execute();
        return $stmt->affected_rows > 0;
    }
}
