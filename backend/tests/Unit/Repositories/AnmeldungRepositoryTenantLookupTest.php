<?php
declare(strict_types=1);

namespace Tests\Unit\Repositories;

use App\Config\TenantContext;
use App\Repositories\AnmeldungRepository;
use PHPUnit\Framework\TestCase;

/**
 * AnmeldungRepository::findTenantIdById() — the intentionally unscoped lookup
 * used by the token-authorized PDF download to learn which tenant owns a row.
 */
class AnmeldungRepositoryTenantLookupTest extends TestCase
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

    /** @param array<string,mixed>|null $row */
    private function makeRepo(?array $row): AnmeldungRepository
    {
        $captured = &$this->captured;

        $db = new class($row, $captured) extends \mysqli {
            /** @param array<string,mixed>|null $row */
            public function __construct(private ?array $row, private array &$captured)
            {
            }

            public function prepare(string $query): \mysqli_stmt|false
            {
                $this->captured['sql'] = $query;

                return new class($this->row, $this->captured) extends \mysqli_stmt {
                    /** @param array<string,mixed>|null $row */
                    public function __construct(private ?array $row, private array &$captured)
                    {
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

    public function testReturnsTenantIdAsInt(): void
    {
        $repo = $this->makeRepo(['tenant_id' => '7']);

        $this->assertSame(7, $repo->findTenantIdById(42));
        $this->assertSame('i', $this->captured['types']);
        $this->assertSame([42], $this->captured['params']);
    }

    public function testIsDeliberatelyNotTenantScoped(): void
    {
        // Works without any TenantContext (the PDF endpoint has no tenant session)
        $repo = $this->makeRepo(['tenant_id' => 3]);

        $this->assertSame(3, $repo->findTenantIdById(1));
        $this->assertStringNotContainsString('tenant_id = ?', $this->captured['sql']);
        $this->assertStringContainsString('WHERE id = ?', $this->captured['sql']);
    }

    public function testReturnsNullForUnknownId(): void
    {
        $this->assertNull($this->makeRepo(null)->findTenantIdById(999));
    }

    public function testReturnsNullWhenRowHasNoTenant(): void
    {
        $this->assertNull($this->makeRepo(['tenant_id' => null])->findTenantIdById(1));
    }
}
