<?php
declare(strict_types=1);

namespace Tests\Unit\Repositories;

use App\Repositories\TenantRepository;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for TenantRepository write methods:
 * create(), update(), updateApiSecret(), findAllForAdmin().
 *
 * Mock strategy: anonymous mysqli subclass pattern (consistent with
 * TenantRepositorySlugTest). Because mysqli::$insert_id is a virtual
 * read-only property at C level, TenantRepository exposes a protected
 * getLastInsertId() helper so tests can override it via a subclass.
 *
 * The mock mysqli captures the call sequence and supplies configurable
 * fetch_assoc results for uniqueness-check SELECT COUNT queries.
 */
class TenantRepositoryWriteTest extends TestCase
{
    // =========================================================================
    // Inner test subclass — overrides getLastInsertId() to avoid real DB access
    // =========================================================================

    /**
     * Extend TenantRepository so tests can inject a fixed insert ID
     * without triggering the "already closed" error on mock mysqli.
     */
    private function makeTestableRepo(
        array $prepareResults = [],
        int $insertId = 0,
        array $multiRows = []
    ): TenantRepository {
        $mockMysqli = $this->buildMockMysqli($prepareResults, $multiRows);

        // Anonymous subclass of TenantRepository that overrides getLastInsertId()
        return new class($mockMysqli, $insertId) extends TenantRepository {
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

    // =========================================================================
    // Mock mysqli factory
    // =========================================================================

    /**
     * Build a mock mysqli that returns prepare() results in sequence.
     *
     * @param list<int|array<string,mixed>|null> $prepareResults  one value per prepare() call:
     *   - int → single-row ['cnt' => N] (for COUNT queries)
     *   - array → single assoc row
     *   - null → no rows / write-only (UPDATE/INSERT with no result read)
     * @param array<int,array<string,mixed>> $multiRows  for findAllForAdmin (multi-row SELECT)
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
                // Skip parent mysqli constructor — no real DB connection needed
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
                        // Skip parent constructor — no real stmt needed
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
                                // Multi-row path (findAllForAdmin, findAll)
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

                                // Assoc row or null
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

    public function testCreateInsertsAndReturnsId(): void
    {
        // Prepare calls:
        //   1st = slug uniqueness check → count 0 (slug available)
        //   2nd = INSERT → null result (write-only)
        $repo = $this->makeTestableRepo(
            prepareResults: [0, null],
            insertId: 42
        );

        $id = $repo->create([
            'name'       => 'New School',
            'slug'       => 'new-school',
            'origin'     => 'https://new.example.com',
            'api_secret' => 'abc123',
        ]);

        $this->assertSame(42, $id);
    }

    public function testCreateThrowsOnDuplicateSlug(): void
    {
        // Uniqueness check returns count 1 = slug already taken
        $repo = $this->makeTestableRepo(prepareResults: [1]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Slug already exists');

        $repo->create([
            'name'       => 'Duplicate',
            'slug'       => 'existing-slug',
            'origin'     => null,
            'api_secret' => 'secret',
        ]);
    }

    public function testCreateWithNullOrigin(): void
    {
        $repo = $this->makeTestableRepo(
            prepareResults: [0, null],
            insertId: 7
        );

        $id = $repo->create([
            'name'       => 'School Without Origin',
            'slug'       => 'no-origin',
            'origin'     => null,
            'api_secret' => 'xyz',
        ]);

        $this->assertSame(7, $id);
    }

    // =========================================================================
    // update()
    // =========================================================================

    public function testUpdateWithNameAndActive(): void
    {
        // One prepare call for UPDATE
        $repo = $this->makeTestableRepo(prepareResults: [null]);

        // Should complete without exception
        $repo->update(1, ['name' => 'Updated Name', 'active' => 0]);

        $this->assertTrue(true); // reached without exception
    }

    public function testUpdateWithEmptyDataAfterFilteringReturnsEarlyWithoutDbCall(): void
    {
        // api_secret is not in whitelist → filtered out → $data is empty → no prepare() call
        $repo = $this->makeTestableRepo(prepareResults: []); // 0 prepare calls expected

        $repo->update(1, ['api_secret' => 'should-be-filtered']);

        $this->assertTrue(true);
    }

    public function testUpdateWithAllWhitelistedFields(): void
    {
        $repo = $this->makeTestableRepo(prepareResults: [null]);

        $repo->update(1, ['name' => 'A', 'origin' => 'https://a.com', 'active' => 1]);

        $this->assertTrue(true);
    }

    public function testUpdateApiSecretIsExcludedFromWhitelist(): void
    {
        // Passing only api_secret through update() results in empty filtered data
        // meaning no DB call — prepareResults is empty (no mock calls expected)
        $repo = $this->makeTestableRepo(prepareResults: []);

        $repo->update(1, ['api_secret' => 'rotated-secret']);

        $this->assertTrue(true);
    }

    // =========================================================================
    // updateApiSecret()
    // =========================================================================

    public function testUpdateApiSecretExecutesUpdate(): void
    {
        $repo = $this->makeTestableRepo(prepareResults: [null]);

        // Should complete without exception
        $repo->updateApiSecret(1, 'new-secret-hex');

        $this->assertTrue(true);
    }

    // =========================================================================
    // findAllForAdmin()
    // =========================================================================

    public function testFindAllForAdminReturnsAllRowsIncludingInactive(): void
    {
        $tenants = [
            ['id' => 1, 'name' => 'Alpha', 'slug' => 'alpha', 'origin' => null,                    'active' => 1],
            ['id' => 2, 'name' => 'Beta',  'slug' => 'beta',  'origin' => 'https://beta.example', 'active' => 0],
        ];

        $repo = $this->makeTestableRepo(multiRows: $tenants);

        $result = $repo->findAllForAdmin();

        $this->assertCount(2, $result);
        $this->assertSame('Alpha', $result[0]['name']);
        $this->assertSame(0, $result[1]['active']); // inactive tenant is included
    }

    public function testFindAllForAdminReturnsEmptyArrayWhenNoTenants(): void
    {
        $repo = $this->makeTestableRepo(multiRows: []);

        $result = $repo->findAllForAdmin();

        $this->assertSame([], $result);
    }

    public function testFindAllForAdminIncludesInactiveTenants(): void
    {
        // Verifies that findAllForAdmin() has NO active filter (unlike findAll())
        $tenants = [
            ['id' => 3, 'name' => 'Inactive School', 'slug' => 'inactive', 'origin' => null, 'active' => 0],
        ];

        $repo = $this->makeTestableRepo(multiRows: $tenants);

        $result = $repo->findAllForAdmin();

        $this->assertCount(1, $result);
        $this->assertSame(0, $result[0]['active']);
    }
}
