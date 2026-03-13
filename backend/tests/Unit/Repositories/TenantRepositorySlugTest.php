<?php
declare(strict_types=1);

namespace Tests\Unit\Repositories;

use App\Repositories\TenantRepository;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for TenantRepository slug-based lookups.
 *
 * Uses anonymous mysqli subclass pattern (consistent with VirusScanServiceTest)
 * to inject a mock database without requiring a real MySQL connection.
 */
class TenantRepositorySlugTest extends TestCase
{
    // =========================================================================
    // Helpers — mock mysqli
    // =========================================================================

    /**
     * Build a TenantRepository backed by a mock mysqli that returns $rows
     * for any query (first row via fetch_assoc, or null if empty).
     *
     * @param array<string,mixed>|null $rowToReturn  null = no rows found
     */
    private function makeRepoWithRow(?array $rowToReturn): TenantRepository
    {
        $mockMysqli = new class($rowToReturn) extends \mysqli {
            private ?array $row;

            public function __construct(?array $row)
            {
                // Skip parent mysqli constructor — no real DB connection
                $this->row = $row;
            }

            public function prepare(string $query): \mysqli_stmt|false
            {
                $row = $this->row;
                return new class($row) extends \mysqli_stmt {
                    private ?array $row;

                    public function __construct(?array $row)
                    {
                        $this->row = $row;
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
                        $row = $this->row;
                        return new class($row) extends \mysqli_result {
                            private ?array $row;
                            private bool $fetched = false;

                            public function __construct(?array $row)
                            {
                                $this->row = $row;
                                // Skip parent constructor
                            }

                            public function fetch_assoc(): array|null|false
                            {
                                if ($this->fetched || $this->row === null) {
                                    return null;
                                }
                                $this->fetched = true;
                                return $this->row;
                            }
                        };
                    }
                };
            }
        };

        return new TenantRepository($mockMysqli);
    }

    // =========================================================================
    // findBySlug
    // =========================================================================

    public function testFindBySlugWithEmptyStringReturnsNull(): void
    {
        // Empty string guard — no DB call should be made
        $repo = $this->makeRepoWithRow(['id' => 1, 'name' => 'Test', 'slug' => 'test', 'origin' => null, 'api_secret' => 'secret', 'active' => 1]);

        $result = $repo->findBySlug('');

        $this->assertNull($result);
    }

    public function testFindBySlugReturnsRowWhenFound(): void
    {
        $expectedRow = [
            'id'         => 1,
            'name'       => 'Default',
            'api_secret' => 'abc123',
            'slug'       => 'default',
            'origin'     => 'https://example.com',
            'active'     => 1,
        ];

        $repo = $this->makeRepoWithRow($expectedRow);

        $result = $repo->findBySlug('default');

        $this->assertSame($expectedRow, $result);
    }

    public function testFindBySlugReturnsNullWhenNotFound(): void
    {
        $repo = $this->makeRepoWithRow(null);

        $result = $repo->findBySlug('nonexistent');

        $this->assertNull($result);
    }

    // =========================================================================
    // findById
    // =========================================================================

    public function testFindByIdReturnsNullWhenNotFound(): void
    {
        $repo = $this->makeRepoWithRow(null);

        $result = $repo->findById(9999);

        $this->assertNull($result);
    }

    public function testFindByIdReturnsRowWhenFound(): void
    {
        $expectedRow = [
            'id'         => 2,
            'name'       => 'School B',
            'api_secret' => 'secret2',
            'slug'       => 'school-b',
            'origin'     => null,
            'active'     => 1,
        ];

        $repo = $this->makeRepoWithRow($expectedRow);

        $result = $repo->findById(2);

        $this->assertSame($expectedRow, $result);
    }

    // =========================================================================
    // findAll
    // =========================================================================

    /**
     * Build a TenantRepository backed by a mock mysqli that returns $rows
     * sequentially from fetch_assoc (multi-row variant for findAll).
     *
     * @param array<int,array<string,mixed>> $rowsToReturn
     */
    private function makeRepoWithRows(array $rowsToReturn): TenantRepository
    {
        $mockMysqli = new class($rowsToReturn) extends \mysqli {
            /** @var array<int,array<string,mixed>> */
            private array $rows;

            /** @param array<int,array<string,mixed>> $rows */
            public function __construct(array $rows)
            {
                $this->rows = $rows;
            }

            public function prepare(string $query): \mysqli_stmt|false
            {
                $rows = $this->rows;
                return new class($rows) extends \mysqli_stmt {
                    /** @var array<int,array<string,mixed>> */
                    private array $rows;

                    /** @param array<int,array<string,mixed>> $rows */
                    public function __construct(array $rows)
                    {
                        $this->rows = $rows;
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
                        $rows = $this->rows;
                        return new class($rows) extends \mysqli_result {
                            /** @var array<int,array<string,mixed>> */
                            private array $rows;
                            private int $index = 0;

                            /** @param array<int,array<string,mixed>> $rows */
                            public function __construct(array $rows)
                            {
                                $this->rows = $rows;
                            }

                            public function fetch_assoc(): array|null|false
                            {
                                if ($this->index >= count($this->rows)) {
                                    return null;
                                }
                                return $this->rows[$this->index++];
                            }
                        };
                    }
                };
            }
        };

        return new TenantRepository($mockMysqli);
    }

    public function testFindAllReturnsEmptyArrayWhenNoTenants(): void
    {
        $repo = $this->makeRepoWithRows([]);

        $result = $repo->findAll();

        $this->assertSame([], $result);
    }

    public function testFindAllReturnsAllRows(): void
    {
        $tenants = [
            ['id' => 1, 'name' => 'Alpha School', 'slug' => 'alpha'],
            ['id' => 2, 'name' => 'Beta School',  'slug' => 'beta'],
        ];

        $repo = $this->makeRepoWithRows($tenants);

        $result = $repo->findAll();

        $this->assertCount(2, $result);
        $this->assertSame($tenants[0], $result[0]);
        $this->assertSame($tenants[1], $result[1]);
    }

    public function testFindAllReturnsSingleTenant(): void
    {
        $tenants = [
            ['id' => 3, 'name' => 'Gamma School', 'slug' => 'gamma'],
        ];

        $repo = $this->makeRepoWithRows($tenants);

        $result = $repo->findAll();

        $this->assertCount(1, $result);
        $this->assertSame('Gamma School', $result[0]['name']);
    }
}
