<?php
declare(strict_types=1);

namespace Tests\Integration\Repositories;

use App\Config\Database;
use App\Config\TenantContext;
use App\Repositories\AnmeldungRepository;
use PHPUnit\Framework\TestCase;

/**
 * DSGVO-critical integration tests for cross-tenant data isolation.
 *
 * These tests verify that AnmeldungRepository strictly scopes all queries
 * to the current tenant — an IDOR or missing WHERE clause would expose
 * another school's student registration data.
 *
 * Requires: anmeldung_test DB with migrated schema (tenants + anmeldungen tables
 * with tenant_id column). Configure in .env.test or phpunit.xml <php> section.
 *
 * @group integration
 */
class AnmeldungRepositoryIsolationTest extends TestCase
{
    private \mysqli $db;
    private AnmeldungRepository $repo;
    private int $tenantAId;
    private int $tenantBId;
    private int $recordAId;

    protected function setUp(): void
    {
        parent::setUp();
        TenantContext::reset();

        $this->db   = Database::getConnection();
        $this->repo = new AnmeldungRepository($this->db);

        // Disable FK checks so we can insert tenants without conflicts
        $this->db->query("SET foreign_key_checks = 0");

        // Create two test tenants
        $stmtA = $this->db->prepare(
            "INSERT INTO tenants (name, api_secret, slug, active) VALUES ('Test Tenant A', 'secret-a-test', 'test-tenant-a', 1)"
        );
        $stmtA->execute();
        $this->tenantAId = (int)$this->db->insert_id;
        $stmtA->close();

        $stmtB = $this->db->prepare(
            "INSERT INTO tenants (name, api_secret, slug, active) VALUES ('Test Tenant B', 'secret-b-test', 'test-tenant-b', 1)"
        );
        $stmtB->execute();
        $this->tenantBId = (int)$this->db->insert_id;
        $stmtB->close();

        // Create one Anmeldung for Tenant A via repository (auto-injects tenant_id)
        TenantContext::initialize($this->tenantAId);
        $this->recordAId = $this->repo->insert([
            'formular'          => 'bs',
            'formular_version'  => '1.0',
            'name'              => 'Max Mustermann',
            'email'             => 'max@example.com',
            'status'            => 'neu',
            'data'              => '{"name":"Max Mustermann"}',
            'pdf_config'        => null,
        ]);

        // Re-enable FK checks
        $this->db->query("SET foreign_key_checks = 1");

        TenantContext::reset();
    }

    protected function tearDown(): void
    {
        // Disable FK to allow clean deletion of test data
        $this->db->query("SET foreign_key_checks = 0");

        $stmt = $this->db->prepare(
            "DELETE FROM anmeldungen WHERE tenant_id IN (?, ?)"
        );
        $stmt->bind_param('ii', $this->tenantAId, $this->tenantBId);
        $stmt->execute();
        $stmt->close();

        $stmt = $this->db->prepare(
            "DELETE FROM tenants WHERE id IN (?, ?)"
        );
        $stmt->bind_param('ii', $this->tenantAId, $this->tenantBId);
        $stmt->execute();
        $stmt->close();

        $this->db->query("SET foreign_key_checks = 1");

        TenantContext::reset();
        parent::tearDown();
    }

    /**
     * Finding a record by ID belonging to tenant A must return null when
     * the current context is tenant B (prevents IDOR).
     */
    public function testFindByIdReturnNullForCrossTenantId(): void
    {
        TenantContext::initialize($this->tenantBId);

        $result = $this->repo->findById($this->recordAId);

        $this->assertNull(
            $result,
            "Tenant B must not be able to access Tenant A records via findById() (IDOR prevention)"
        );
    }

    /**
     * Paginated listing for tenant B must return zero rows when all
     * existing records belong to tenant A.
     */
    public function testFindPaginatedReturnsZeroForTenantB(): void
    {
        TenantContext::initialize($this->tenantBId);

        $result = $this->repo->findPaginated();

        $this->assertSame(0, $result['total'], "Tenant B total must be 0 when only Tenant A has records");
        $this->assertEmpty($result['items'], "Tenant B items must be empty when only Tenant A has records");
    }

    /**
     * Soft-deleted records listing for tenant B must return zero rows
     * when only tenant A has soft-deleted entries.
     */
    public function testFindDeletedReturnsZeroForTenantB(): void
    {
        // Soft-delete the Tenant A record as Tenant A first
        TenantContext::initialize($this->tenantAId);
        $this->repo->softDelete($this->recordAId);
        TenantContext::reset();

        // Now check as Tenant B
        TenantContext::initialize($this->tenantBId);

        $result = $this->repo->findDeleted();

        $this->assertEmpty(
            $result,
            "Tenant B must not see Tenant A's soft-deleted records"
        );
    }

    /**
     * Statistics aggregation for tenant B must return zero counts when
     * all submissions belong to tenant A.
     */
    public function testGetStatisticsReturnsZeroCountsForTenantB(): void
    {
        TenantContext::initialize($this->tenantBId);

        $result = $this->repo->getStatistics();

        $this->assertEmpty(
            $result,
            "Tenant B statistics must be empty when all records belong to Tenant A"
        );
    }

    /**
     * INSERT via repository must automatically stamp the current
     * TenantContext tenant_id onto the new row.
     */
    public function testInsertAutoInjectsTenantId(): void
    {
        TenantContext::initialize($this->tenantAId);

        $newId = $this->repo->insert([
            'formular'         => 'bk',
            'formular_version' => '1.0',
            'name'             => 'Anna Beispiel',
            'email'            => 'anna@example.com',
            'status'           => 'neu',
            'data'             => '{"name":"Anna Beispiel"}',
            'pdf_config'       => null,
        ]);

        // Verify tenant_id via direct DB query (bypass repository abstraction)
        $stmt = $this->db->prepare("SELECT tenant_id FROM anmeldungen WHERE id = ?");
        $stmt->bind_param('i', $newId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        $this->assertNotNull($row, "Inserted record must exist in DB");
        $this->assertSame(
            $this->tenantAId,
            (int)$row['tenant_id'],
            "insert() must auto-inject TenantContext tenant_id onto the new row"
        );
    }

    /**
     * Soft-deleting a record owned by tenant A while operating as
     * tenant B must leave the tenant A record untouched.
     */
    public function testSoftDeleteDoesNotAffectOtherTenantRecord(): void
    {
        // Attempt to soft-delete Tenant A's record as Tenant B
        TenantContext::initialize($this->tenantBId);
        $result = $this->repo->softDelete($this->recordAId);
        TenantContext::reset();

        $this->assertFalse(
            $result,
            "softDelete() must return false when the record belongs to a different tenant"
        );

        // Verify Tenant A's record still exists and is not deleted
        TenantContext::initialize($this->tenantAId);
        $record = $this->repo->findById($this->recordAId);

        $this->assertNotNull(
            $record,
            "Tenant A's record must still be accessible after Tenant B's failed delete attempt"
        );
    }
}
