<?php
declare(strict_types=1);

namespace Tests\Unit\Repositories;

use App\Config\TenantContext;
use App\Repositories\AnmeldungRepository;
use PHPUnit\Framework\TestCase;

/**
 * Schreibzugriffe im All-Tenants-Modus (Platform-Admin): getTenantId() wirft dort,
 * die Repository-Methoden müssen den Tenant der Zeile ermitteln und trotzdem nach tenant_id filtern.
 */
class AnmeldungRepositoryAllTenantsWriteTest extends TestCase
{
    /** @var list<array{sql: string, types: string, params: array<int,mixed>}> */
    private array $executed;

    protected function setUp(): void
    {
        parent::setUp();
        TenantContext::reset();
        $this->executed = [];
    }

    protected function tearDown(): void
    {
        TenantContext::reset();
        parent::tearDown();
    }

    /**
     * @param list<array<string,mixed>> $rows Ergebnis jeder SELECT-Abfrage (Zeilen id/tenant_id)
     */
    private function makeRepo(array $rows): AnmeldungRepository
    {
        $executed = &$this->executed;

        $db = new class($rows, $executed) extends \mysqli {
            /** @param list<array<string,mixed>> $rows */
            public function __construct(private array $rows, private array &$executed)
            {
            }

            public function prepare(string $query): \mysqli_stmt|false
            {
                return new class($query, $this->rows, $this->executed) extends \mysqli_stmt {
                    private string $types = '';
                    /** @var array<int,mixed> */
                    private array $params = [];

                    /** @param list<array<string,mixed>> $rows */
                    public function __construct(private string $sql, private array $rows, private array &$executed)
                    {
                    }

                    public function bind_param(string $types, mixed &...$vars): bool
                    {
                        $this->types  = $types;
                        $this->params = $vars;
                        return true;
                    }

                    public function execute(?array $params = null): bool
                    {
                        $this->executed[] = ['sql' => $this->sql, 'types' => $this->types, 'params' => $this->params];
                        return true;
                    }

                    public function get_result(): \mysqli_result|false
                    {
                        return new class($this->rows) extends \mysqli_result {
                            private int $i = 0;

                            /** @param list<array<string,mixed>> $rows */
                            public function __construct(private array $rows)
                            {
                            }

                            public function fetch_assoc(): array|null|false
                            {
                                return $this->rows[$this->i++] ?? null;
                            }
                        };
                    }
                };
            }
        };

        return new AnmeldungRepository($db);
    }

    /** @return list<array{sql: string, types: string, params: array<int,mixed>}> */
    private function writes(): array
    {
        return array_values(array_filter(
            $this->executed,
            static fn(array $e): bool => !str_starts_with(ltrim($e['sql']), 'SELECT')
        ));
    }

    public function testWritesOnUnknownIdDoNothingInAllTenantsMode(): void
    {
        TenantContext::initAllTenants();
        $repo = $this->makeRepo([]);

        $this->assertFalse($repo->updateStatus(99, 'exportiert'));
        $this->assertFalse($repo->softDelete(99));
        $this->assertFalse($repo->restore(99));
        $this->assertFalse($repo->hardDelete(99));
        $this->assertSame([], $this->writes());
    }

    public function testBulkWritesOnUnknownIdsDoNothingInAllTenantsMode(): void
    {
        TenantContext::initAllTenants();
        $repo = $this->makeRepo([]);

        $this->assertSame(0, $repo->bulkUpdateStatus([98, 99], 'archiviert'));
        $this->assertSame(0, $repo->bulkSoftDelete([98, 99]));
        $this->assertSame([], $this->writes());
    }

    public function testTenantOfTheRowIsLookedUpInAllTenantsMode(): void
    {
        TenantContext::initAllTenants();
        $repo = $this->makeRepo([['tenant_id' => '4']]);

        try {
            $repo->softDelete(3);
        } catch (\Error) {
            // affected_rows lässt sich in der Attrappe nicht lesen; relevant ist das ausgeführte Statement
        }

        $write = $this->writes()[0];
        $this->assertStringContainsString('tenant_id = ?', $write['sql']);
        $this->assertSame([3, 4], $write['params']);
    }
}
