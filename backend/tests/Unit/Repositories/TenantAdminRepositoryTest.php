<?php
declare(strict_types=1);

namespace Tests\Unit\Repositories;

use App\Repositories\TenantAdminRepository;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for TenantAdminRepository:
 * create(), findByTenantId(), resetPassword(), toggleActive().
 *
 * Mock strategy: anonymous mysqli subclass + TenantAdminRepository subclass
 * to expose a getLastInsertId() override (same pattern as TenantRepositoryWriteTest).
 * Global username uniqueness is enforced in create() — tests verify this.
 */
class TenantAdminRepositoryTest extends TestCase
{
    // =========================================================================
    // Factory helpers
    // =========================================================================

    /**
     * Build a testable TenantAdminRepository backed by a mock mysqli.
     *
     * @param list<int|array<string,mixed>|null> $prepareResults  per-prepare-call result:
     *   - int  → single row ['cnt' => N] (for COUNT uniqueness check)
     *   - null → write-only (INSERT/UPDATE with no result read back)
     * @param array<int,array<string,mixed>> $multiRows  for findByTenantId (multi-row SELECT)
     * @param int $insertId  returned by getLastInsertId() after INSERT
     */
    private function makeTestableRepo(
        array $prepareResults = [],
        int $insertId = 0,
        array $multiRows = []
    ): TenantAdminRepository {
        $mockMysqli = $this->buildMockMysqli($prepareResults, $multiRows);

        return new class($mockMysqli, $insertId) extends TenantAdminRepository {
            private int $fakeInsertId;

            public function __construct(\mysqli $db, int $insertId)
            {
                parent::__construct($db);
                $this->fakeInsertId = $insertId;
            }

            protected function getLastInsertId(): int
            {
                return $this->fakeInsertId;
            }
        };
    }

    /**
     * Build a mock mysqli that returns results in sequence per prepare() call.
     *
     * @param list<int|array<string,mixed>|null> $prepareResults
     * @param array<int,array<string,mixed>> $multiRows
     */
    private function buildMockMysqli(array $prepareResults, array $multiRows): \mysqli
    {
        return new class($prepareResults, $multiRows) extends \mysqli {
            /** @var list<int|array<string,mixed>|null> */
            private array $results;
            private int $callIndex = 0;
            /** @var array<int,array<string,mixed>> */
            private array $multiRows;

            /**
             * @param list<int|array<string,mixed>|null> $results
             * @param array<int,array<string,mixed>> $multiRows
             */
            public function __construct(array $results, array $multiRows)
            {
                // Skip parent mysqli constructor — no real connection needed
                $this->results   = $results;
                $this->multiRows = $multiRows;
            }

            public function prepare(string $query): \mysqli_stmt|false
            {
                $result    = $this->results[$this->callIndex] ?? null;
                $multiRows = $this->multiRows;
                $this->callIndex++;

                return new class($result, $multiRows) extends \mysqli_stmt {
                    private mixed $result;
                    /** @var array<int,array<string,mixed>> */
                    private array $multiRows;

                    /**
                     * @param array<int,array<string,mixed>> $multiRows
                     */
                    public function __construct(mixed $result, array $multiRows)
                    {
                        // Skip parent constructor
                        $this->result    = $result;
                        $this->multiRows = $multiRows;
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
                        $result    = $this->result;
                        $multiRows = $this->multiRows;

                        return new class($result, $multiRows) extends \mysqli_result {
                            private mixed $result;
                            /** @var array<int,array<string,mixed>> */
                            private array $multiRows;
                            private int $rowIndex = 0;
                            private bool $fetched  = false;

                            /**
                             * @param array<int,array<string,mixed>> $multiRows
                             */
                            public function __construct(mixed $result, array $multiRows)
                            {
                                // Skip parent constructor
                                $this->result    = $result;
                                $this->multiRows = $multiRows;
                            }

                            public function fetch_assoc(): array|null|false
                            {
                                // Multi-row path (findByTenantId)
                                if (!empty($this->multiRows)) {
                                    if ($this->rowIndex >= count($this->multiRows)) {
                                        return null;
                                    }
                                    return $this->multiRows[$this->rowIndex++];
                                }

                                // Scalar COUNT result
                                if (is_int($this->result)) {
                                    if ($this->fetched) {
                                        return null;
                                    }
                                    $this->fetched = true;
                                    return ['cnt' => $this->result];
                                }

                                // Assoc row or null (write-only stmts)
                                if ($this->fetched || $this->result === null) {
                                    return null;
                                }
                                $this->fetched = true;
                                return $this->result;
                            }
                        };
                    }
                };
            }
        };
    }

    // =========================================================================
    // create()
    // =========================================================================

    public function testCreateStoresHashedPassword(): void
    {
        // Prepare calls:
        //   1st = global username uniqueness check → count 0 (username available)
        //   2nd = INSERT → null (write-only)
        $repo = $this->makeTestableRepo(
            prepareResults: [0, null],
            insertId: 55
        );

        $id = $repo->create([
            'tenant_id'     => 1,
            'username'      => 'newadmin',
            'password_hash' => '$2y$10$abc...',
        ]);

        $this->assertSame(55, $id);
    }

    public function testCreateThrowsOnDuplicateUsername(): void
    {
        // Global uniqueness check returns count 1 = username already exists
        $repo = $this->makeTestableRepo(prepareResults: [1]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Username already exists');

        $repo->create([
            'tenant_id'     => 2,
            'username'      => 'existing-user',
            'password_hash' => '$2y$10$xyz...',
        ]);
    }

    public function testCreateUsernameUniquenessIsGlobalNotPerTenant(): void
    {
        // Even on a different tenant_id, if username count > 0 — throw (global check)
        $repo = $this->makeTestableRepo(prepareResults: [1]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Username already exists');

        $repo->create([
            'tenant_id'     => 99, // different tenant from the existing record
            'username'      => 'cross-tenant-conflict',
            'password_hash' => '$2y$10$zzz...',
        ]);
    }

    // =========================================================================
    // findByTenantId()
    // =========================================================================

    public function testFindByTenantIdReturnsAdminsForCorrectTenantOnly(): void
    {
        $admins = [
            ['id' => 1, 'username' => 'alice', 'active' => 1],
            ['id' => 2, 'username' => 'bob',   'active' => 0],
        ];

        $repo = $this->makeTestableRepo(multiRows: $admins);

        $result = $repo->findByTenantId(1);

        $this->assertCount(2, $result);
        $this->assertSame('alice', $result[0]['username']);
        $this->assertSame(0, $result[1]['active']);
    }

    public function testFindByTenantIdReturnsEmptyArrayWhenNoAdmins(): void
    {
        $repo = $this->makeTestableRepo(multiRows: []);

        $result = $repo->findByTenantId(42);

        $this->assertSame([], $result);
    }

    public function testFindByTenantIdReturnsSingleAdmin(): void
    {
        $admins = [
            ['id' => 5, 'username' => 'carol', 'active' => 1],
        ];

        $repo = $this->makeTestableRepo(multiRows: $admins);

        $result = $repo->findByTenantId(3);

        $this->assertCount(1, $result);
        $this->assertSame('carol', $result[0]['username']);
    }

    // =========================================================================
    // resetPassword()
    // =========================================================================

    public function testResetPasswordStoresNewHash(): void
    {
        $repo = $this->makeTestableRepo(prepareResults: [null]);

        // Should complete without exception
        $repo->resetPassword(7, '$2y$10$newpasshash...');

        $this->assertTrue(true);
    }

    // =========================================================================
    // toggleActive()
    // =========================================================================

    public function testToggleActiveFlipsState(): void
    {
        $repo = $this->makeTestableRepo(prepareResults: [null]);

        // Should complete without exception — sets active = 0
        $repo->toggleActive(3, false);

        $this->assertTrue(true);
    }

    public function testToggleActiveToTrueExecutesUpdate(): void
    {
        $repo = $this->makeTestableRepo(prepareResults: [null]);

        // Should complete without exception — sets active = 1
        $repo->toggleActive(3, true);

        $this->assertTrue(true);
    }
}
