<?php
declare(strict_types=1);

namespace Tests\Integration\Forms;

use App\Config\Database;
use App\Config\TenantContext;
use App\Repositories\FormConfigRepository;
use App\Repositories\FormDraftRepository;
use App\Repositories\FormResourceRepository;
use App\Repositories\FormRevisionRepository;
use PHPUnit\Framework\TestCase;

/**
 * Base for form-editor integration tests: two throw-away tenants in the test database.
 * Needs the 3.1 schema (database/schema.sql). Deleting the tenants cascades to all their rows.
 *
 * @group integration
 */
abstract class FormEditorTestCase extends TestCase
{
    protected \mysqli $db;
    protected int $tenantA;
    protected int $tenantB;

    protected FormConfigRepository $configs;
    protected FormResourceRepository $resources;
    protected FormDraftRepository $drafts;
    protected FormRevisionRepository $revisions;

    protected function setUp(): void
    {
        parent::setUp();
        TenantContext::reset();

        $this->db = Database::getConnection();
        $this->tenantA = $this->createTenant('a');
        $this->tenantB = $this->createTenant('b');

        $this->configs   = new FormConfigRepository($this->db);
        $this->resources = new FormResourceRepository($this->db);
        $this->drafts    = new FormDraftRepository($this->db);
        $this->revisions = new FormRevisionRepository($this->db);

        TenantContext::initialize($this->tenantA);
    }

    protected function tearDown(): void
    {
        TenantContext::reset();
        if ($this->db->errno === 0) {
            // A failed test may leave a transaction open.
            @$this->db->rollback();
        }
        $stmt = $this->db->prepare('DELETE FROM tenants WHERE id IN (?, ?)');
        $stmt->bind_param('ii', $this->tenantA, $this->tenantB);
        $stmt->execute();
        parent::tearDown();
    }

    private function createTenant(string $suffix): int
    {
        $slug   = 'it-forms-' . $suffix . '-' . bin2hex(random_bytes(4));
        $name   = 'IT ' . $slug;
        $secret = bin2hex(random_bytes(16));
        $stmt   = $this->db->prepare('INSERT INTO tenants (name, slug, api_secret, active) VALUES (?, ?, ?, 1)');
        $stmt->bind_param('sss', $name, $slug, $secret);
        $stmt->execute();
        return (int)$this->db->insert_id;
    }

    protected function asTenant(int $tenantId): void
    {
        TenantContext::initialize($tenantId);
    }

    /** @return array<string,mixed> */
    protected function minimalSurvey(string ...$names): array
    {
        $names = $names ?: ['Vorname', 'email'];
        return ['pages' => [['name' => 'p1', 'elements' => array_map(
            static fn (string $n): array => ['type' => 'text', 'name' => $n],
            $names
        )]]];
    }

    protected function surveyJson(string ...$names): string
    {
        return json_encode($this->minimalSurvey(...$names), JSON_THROW_ON_ERROR);
    }

    protected function countRows(string $table, int $tenantId): int
    {
        $stmt = $this->db->prepare("SELECT COUNT(*) AS c FROM {$table} WHERE tenant_id = ?");
        $stmt->bind_param('i', $tenantId);
        $stmt->execute();
        return (int)$stmt->get_result()->fetch_assoc()['c'];
    }
}
