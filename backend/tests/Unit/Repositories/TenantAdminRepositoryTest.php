<?php
declare(strict_types=1);

namespace Tests\Unit\Repositories;

use App\Repositories\TenantAdminRepository;
use PHPUnit\Framework\TestCase;

/**
 * Wave 0 stubs for TenantAdminRepository CRUD methods.
 *
 * These tests are AMBER (markTestIncomplete) — the class skeleton exists
 * but all methods throw RuntimeException('Not implemented').
 * They will be green after Plan 02 implements the SQL.
 *
 * Using anonymous mysqli subclass pattern (consistent with TenantRepositorySlugTest).
 */
class TenantAdminRepositoryTest extends TestCase
{
    // =========================================================================
    // Mock helper
    // =========================================================================

    /**
     * Build a TenantAdminRepository backed by a mock mysqli.
     * The stub methods throw RuntimeException before reaching any DB code,
     * so the mock will not be exercised in Wave 0 — it is present for test
     * structure completeness.
     */
    private function makeRepo(): TenantAdminRepository
    {
        $mockMysqli = new class extends \mysqli {
            public function __construct()
            {
                // Skip parent mysqli constructor — no real DB connection
            }

            public function prepare(string $query): \mysqli_stmt|false
            {
                return new class extends \mysqli_stmt {
                    public function __construct()
                    {
                        // Skip parent constructor
                    }

                    public function bind_param(string $types, mixed &...$vars): bool
                    {
                        return true;
                    }

                    public function execute(?array $params = null): bool
                    {
                        return true;
                    }

                    public function get_result(): \mysqli_result|false
                    {
                        return new class extends \mysqli_result {
                            public function __construct()
                            {
                                // Skip parent constructor
                            }

                            public function fetch_assoc(): array|null|false
                            {
                                return null;
                            }
                        };
                    }
                };
            }
        };

        return new TenantAdminRepository($mockMysqli);
    }

    // =========================================================================
    // create
    // =========================================================================

    public function testCreateStoresHashedPassword(): void
    {
        $this->markTestIncomplete('TenantAdminRepository not yet fully implemented');
    }

    // =========================================================================
    // findByTenantId
    // =========================================================================

    public function testFindByTenantIdReturnsAdminsForCorrectTenantOnly(): void
    {
        $this->markTestIncomplete('TenantAdminRepository not yet fully implemented');
    }

    // =========================================================================
    // resetPassword
    // =========================================================================

    public function testResetPasswordStoresNewHash(): void
    {
        $this->markTestIncomplete('TenantAdminRepository not yet fully implemented');
    }

    // =========================================================================
    // toggleActive
    // =========================================================================

    public function testToggleActiveFlipsState(): void
    {
        $this->markTestIncomplete('TenantAdminRepository not yet fully implemented');
    }
}
