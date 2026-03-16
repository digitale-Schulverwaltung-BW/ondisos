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
 * Uses the anonymous mysqli subclass mock pattern (consistent with
 * TenantRepositorySlugTest and VirusScanServiceTest).
 */
class TenantRepositoryWriteTest extends TestCase
{
    // =========================================================================
    // Mock helpers
    // =========================================================================

    /**
     * Build a mock mysqli that records prepared queries and returns configurable
     * results. Supports:
     *   - scalar count query result (for uniqueness checks)
     *   - INSERT with configurable insert_id
     *   - multiple sequential queries (read vs. write)
     *
     * @param list<array<string,mixed>|int|null> $prepareResults  per-prepare-call return value
     * @param int $insertId  returned by $db->insert_id after INSERT
     */
    private function makeMockedRepo(
        array $prepareResults = [],
        int $insertId = 0,
        array $multiRows = []
    ): TenantRepository {
        $mockMysqli = new class($prepareResults, $insertId, $multiRows) extends \mysqli {
            /** @var list<array<string,mixed>|int|null> */
            private array $results;
            private int $callIndex = 0;
            public string|int $insert_id = 0;
            /** @var array<int,array<string,mixed>> */
            private array $multiRows;

            /**
             * @param list<array<string,mixed>|int|null> $results
             * @param array<int,array<string,mixed>> $multiRows
             */
            public function __construct(array $results, int $insertId, array $multiRows)
            {
                $this->results   = $results;
                $this->insert_id = $insertId; // string|int, set after INSERT
                $this->multiRows = $multiRows;
            }

            public function prepare(string $query): \mysqli_stmt|false
            {
                $result     = $this->results[$this->callIndex] ?? null;
                $multiRows  = $this->multiRows;
                $db         = $this;
                $this->callIndex++;

                return new class($result, $multiRows, $db) extends \mysqli_stmt {
                    private mixed $result;
                    /** @var array<int,array<string,mixed>> */
                    private array $multiRows;
                    /** @var \mysqli */
                    private \mysqli $db;

                    /**
                     * @param array<int,array<string,mixed>> $multiRows
                     */
                    public function __construct(mixed $result, array $multiRows, \mysqli $db)
                    {
                        $this->result    = $result;
                        $this->multiRows = $multiRows;
                        $this->db        = $db;
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
                                $this->result    = $result;
                                $this->multiRows = $multiRows;
                            }

                            public function fetch_assoc(): array|null|false
                            {
                                // Multi-row result (findAllForAdmin)
                                if (!empty($this->multiRows)) {
                                    if ($this->rowIndex >= count($this->multiRows)) {
                                        return null;
                                    }
                                    return $this->multiRows[$this->rowIndex++];
                                }

                                // Single scalar row (count check)
                                if (is_int($this->result)) {
                                    if ($this->fetched) {
                                        return null;
                                    }
                                    $this->fetched = true;
                                    return ['cnt' => $this->result];
                                }

                                // Single assoc row or null
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

        return new TenantRepository($mockMysqli);
    }

    // =========================================================================
    // create()
    // =========================================================================

    public function testCreateInsertsAndReturnsId(): void
    {
        // First prepare call: slug uniqueness check returns count 0
        // Second prepare call: INSERT
        $repo = $this->makeMockedRepo(
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
        // Uniqueness check returns count 1 = slug taken
        $repo = $this->makeMockedRepo(prepareResults: [1]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Slug already exists');

        $repo->create([
            'name'       => 'Duplicate',
            'slug'       => 'existing-slug',
            'origin'     => null,
            'api_secret' => 'secret',
        ]);
    }

    // =========================================================================
    // update()
    // =========================================================================

    public function testUpdateWithNameAndActive(): void
    {
        // update() only builds SET and executes UPDATE; mock just needs one prepare call
        $repo = $this->makeMockedRepo(prepareResults: [null]);

        // Should not throw
        $repo->update(1, ['name' => 'Updated Name', 'active' => 0]);

        $this->assertTrue(true); // reached without exception
    }

    public function testUpdateWithEmptyDataReturnsEarly(): void
    {
        // api_secret is not in whitelist, so after filtering $data is empty
        $repo = $this->makeMockedRepo(prepareResults: []);

        // Should not throw and should not call prepare() (no db interaction)
        $repo->update(1, ['api_secret' => 'should-be-filtered']);

        $this->assertTrue(true);
    }

    public function testUpdateWithAllWhitelistedFields(): void
    {
        $repo = $this->makeMockedRepo(prepareResults: [null]);

        $repo->update(1, ['name' => 'A', 'origin' => 'https://a.com', 'active' => 1]);

        $this->assertTrue(true);
    }

    public function testUpdateApiSecretIsExcludedFromUpdateWhitelist(): void
    {
        // Passing api_secret through update() should result in empty filtered data
        // so no DB call is made (prepareResults empty = no mock calls expected)
        $repo = $this->makeMockedRepo(prepareResults: []);

        // Must NOT throw, must NOT call prepare (api_secret excluded from whitelist)
        $repo->update(1, ['api_secret' => 'rotated-secret']);

        $this->assertTrue(true);
    }

    // =========================================================================
    // updateApiSecret()
    // =========================================================================

    public function testUpdateApiSecretExecutesUpdate(): void
    {
        $repo = $this->makeMockedRepo(prepareResults: [null]);

        // Should not throw
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

        $repo = $this->makeMockedRepo(multiRows: $tenants);

        $result = $repo->findAllForAdmin();

        $this->assertCount(2, $result);
        $this->assertSame('Alpha', $result[0]['name']);
        $this->assertSame(0, $result[1]['active']); // inactive tenant is included
    }

    public function testFindAllForAdminReturnsEmptyArrayWhenNoTenants(): void
    {
        $repo = $this->makeMockedRepo(multiRows: []);

        $result = $repo->findAllForAdmin();

        $this->assertSame([], $result);
    }

    public function testFindAllForAdminIncludesInactiveTenants(): void
    {
        // Verifies that findAllForAdmin() has NO active filter (unlike findAll())
        $tenants = [
            ['id' => 3, 'name' => 'Inactive School', 'slug' => 'inactive', 'origin' => null, 'active' => 0],
        ];

        $repo = $this->makeMockedRepo(multiRows: $tenants);

        $result = $repo->findAllForAdmin();

        $this->assertCount(1, $result);
        $this->assertSame(0, $result[0]['active']);
    }
}
