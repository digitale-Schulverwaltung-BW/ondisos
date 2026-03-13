<?php
declare(strict_types=1);

namespace Tests\Integration\Repositories;

use App\Config\TenantContext;
use PHPUnit\Framework\TestCase;

/**
 * DSGVO-critical integration tests for cross-tenant data isolation.
 *
 * These tests verify that AnmeldungRepository strictly scopes all queries
 * to the current tenant — an IDOR or missing WHERE clause would expose
 * another school's student registration data.
 *
 * @group integration
 *
 * Wave 0 stubs — implemented in Plan 04.
 * Using markTestIncomplete so the Unit suite runs cleanly.
 */
class AnmeldungRepositoryIsolationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        TenantContext::reset();
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        TenantContext::reset();
    }

    /**
     * Finding a record by ID belonging to tenant A must return null when
     * the current context is tenant B (prevents IDOR).
     */
    public function testFindByIdReturnNullForCrossTenantId(): void
    {
        // Requires anmeldung_test DB with tenant-scoped data fixtures.
        // setUp seeds tenant A record, calls repository as tenant B.
        $this->markTestIncomplete('Wave 0 stub — implemented in Plan 04');
    }

    /**
     * Paginated listing for tenant B must return zero rows when all
     * existing records belong to tenant A.
     */
    public function testFindPaginatedReturnsZeroForTenantB(): void
    {
        $this->markTestIncomplete('Wave 0 stub — implemented in Plan 04');
    }

    /**
     * Soft-deleted records listing for tenant B must return zero rows
     * when only tenant A has soft-deleted entries.
     */
    public function testFindDeletedReturnsZeroForTenantB(): void
    {
        $this->markTestIncomplete('Wave 0 stub — implemented in Plan 04');
    }

    /**
     * Statistics aggregation for tenant B must return zero counts when
     * all submissions belong to tenant A.
     */
    public function testGetStatisticsReturnsZeroCountsForTenantB(): void
    {
        $this->markTestIncomplete('Wave 0 stub — implemented in Plan 04');
    }

    /**
     * INSERT via repository must automatically stamp the current
     * TenantContext tenant_id onto the new row.
     */
    public function testInsertAutoInjectsTenantId(): void
    {
        $this->markTestIncomplete('Wave 0 stub — implemented in Plan 04');
    }

    /**
     * Soft-deleting a record owned by tenant A while operating as
     * tenant B must leave the tenant A record untouched.
     */
    public function testSoftDeleteDoesNotAffectOtherTenantRecord(): void
    {
        $this->markTestIncomplete('Wave 0 stub — implemented in Plan 04');
    }
}
