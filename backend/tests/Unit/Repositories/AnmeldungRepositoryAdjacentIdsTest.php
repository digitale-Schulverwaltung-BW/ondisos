<?php
declare(strict_types=1);

namespace Tests\Unit\Repositories;

use App\Config\TenantContext;
use App\Repositories\AnmeldungRepository;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Unit tests for AnmeldungRepository::findAdjacentIds().
 *
 * Verifies tenant scoping in the generated SQL and the bind_param
 * type string / values, using a recording mysqli mock (no real DB).
 */
class AnmeldungRepositoryAdjacentIdsTest extends TestCase
{
    /** @var array{sql: ?string, types: ?string, params: array<int,mixed>} */
    private array $captured;

    protected function setUp(): void
    {
        parent::setUp();
        TenantContext::reset();
        $this->captured = ['sql' => null, 'types' => null, 'params' => []];
    }

    protected function tearDown(): void
    {
        TenantContext::reset();
        parent::tearDown();
    }

    /**
     * @param array<string,mixed>|null $row Row returned by the SELECT
     */
    private function makeRepo(?array $row): AnmeldungRepository
    {
        $captured = &$this->captured;

        $db = new class($row, $captured) extends \mysqli {
            /** @param array<string,mixed>|null $row */
            public function __construct(private ?array $row, private array &$captured)
            {
                // Skip parent constructor — no real DB connection needed
            }

            public function prepare(string $query): \mysqli_stmt|false
            {
                $this->captured['sql'] = $query;

                return new class($this->row, $this->captured) extends \mysqli_stmt {
                    /** @param array<string,mixed>|null $row */
                    public function __construct(private ?array $row, private array &$captured)
                    {
                        // Skip parent constructor
                    }

                    public function bind_param(string $types, mixed &...$vars): bool
                    {
                        $this->captured['types'] = $types;
                        $this->captured['params'] = $vars;
                        return true;
                    }

                    public function execute(?array $params = null): bool
                    {
                        return true;
                    }

                    public function get_result(): \mysqli_result|false
                    {
                        return new class($this->row) extends \mysqli_result {
                            /** @param array<string,mixed>|null $row */
                            public function __construct(private ?array $row)
                            {
                                // Skip parent constructor
                            }

                            public function fetch_assoc(): array|null|false
                            {
                                return $this->row;
                            }
                        };
                    }
                };
            }
        };

        return new AnmeldungRepository($db);
    }

    public function testScopesBothSubqueriesToCurrentTenant(): void
    {
        TenantContext::initialize(7);
        $repo = $this->makeRepo(['prev_id' => 4, 'next_id' => 9]);

        $result = $repo->findAdjacentIds(5, 'bs');

        $this->assertSame(['prev' => 4, 'next' => 9], $result);
        $this->assertSame(2, substr_count($this->captured['sql'], 'tenant_id = ?'));
        $this->assertSame('siisii', $this->captured['types']);
        $this->assertSame(['bs', 5, 7, 'bs', 5, 7], $this->captured['params']);
    }

    public function testSkipsTenantFilterInAllTenantsMode(): void
    {
        TenantContext::initAllTenants();
        $repo = $this->makeRepo(['prev_id' => 1, 'next_id' => 3]);

        $result = $repo->findAdjacentIds(2, 'vabo');

        $this->assertSame(['prev' => 1, 'next' => 3], $result);
        $this->assertStringNotContainsString('tenant_id', $this->captured['sql']);
        $this->assertSame('sisi', $this->captured['types']);
        $this->assertSame(['vabo', 2, 'vabo', 2], $this->captured['params']);
    }

    public function testReturnsNullForMissingNeighbours(): void
    {
        TenantContext::initialize(1);
        $repo = $this->makeRepo(['prev_id' => null, 'next_id' => null]);

        $this->assertSame(
            ['prev' => null, 'next' => null],
            $repo->findAdjacentIds(1, 'bs')
        );
    }

    public function testCastsStringIdsToInt(): void
    {
        TenantContext::initialize(1);
        $repo = $this->makeRepo(['prev_id' => '12', 'next_id' => '14']);

        $this->assertSame(
            ['prev' => 12, 'next' => 14],
            $repo->findAdjacentIds(13, 'bs')
        );
    }

    public function testThrowsWhenTenantContextNotInitialized(): void
    {
        $repo = $this->makeRepo(['prev_id' => null, 'next_id' => null]);

        $this->expectException(RuntimeException::class);
        $repo->findAdjacentIds(1, 'bs');
    }
}
