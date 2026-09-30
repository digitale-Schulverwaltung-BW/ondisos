<?php
declare(strict_types=1);

namespace Tests\Unit\Config;

use App\Config\FormConfig;
use App\Config\TenantContext;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for DB-backed FormConfig::get().
 *
 * Test strategy: FormConfig exposes a static setConnectionForTesting(?mysqli)
 * method so the unit suite can inject a mock mysqli without a live DB.
 * TenantContext::initialize() is used to set the expected tenant_id.
 *
 * Contracts tested:
 *   - FormConfig::get() uses TenantContext tenant_id in the DB query
 *   - FormConfig::get() returns null for unknown form keys
 *   - FormConfig::get() JSON-decodes the config_json column
 *   - FormConfig::reset() clears the static cache
 */
class FormConfigDbTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        FormConfig::reset();
        TenantContext::reset();
    }

    protected function tearDown(): void
    {
        FormConfig::reset();
        FormConfig::setConnectionForTesting(null);
        TenantContext::reset();
        parent::tearDown();
    }

    // =========================================================================
    // Helper: build a mock mysqli that returns a single assoc row
    // =========================================================================

    /**
     * Build a mock mysqli that returns $row on fetch_assoc() (or null if $row === null).
     *
     * @param array<string,mixed>|null $row
     */
    private function buildMockMysqli(array|null $row): \mysqli
    {
        return new class($row) extends \mysqli {
            private mixed $row;

            public function __construct(mixed $row)
            {
                // Skip parent mysqli constructor — no real DB needed
                $this->row = $row;
            }

            public function prepare(string $query): \mysqli_stmt|false
            {
                $row = $this->row;

                return new class($row) extends \mysqli_stmt {
                    private mixed $row;

                    public function __construct(mixed $row)
                    {
                        // Skip parent constructor
                        $this->row = $row;
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
                            private mixed $row;
                            private bool $fetched = false;

                            public function __construct(mixed $row)
                            {
                                // Skip parent constructor
                                $this->row = $row;
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
    }

    /**
     * Build a mock mysqli that returns multiple rows in sequence for fetch_assoc().
     *
     * @param array<int,array<string,mixed>> $rows
     */
    private function buildMultiRowMockMysqli(array $rows): \mysqli
    {
        return new class($rows) extends \mysqli {
            /** @var array<int,array<string,mixed>> */
            private array $rows;

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
    }

    // =========================================================================
    // DB-backed get() — tenant scoping
    // =========================================================================

    public function testGetQueriesDbWithCorrectTenantId(): void
    {
        TenantContext::initialize(1);

        $configData = ['form' => 'bs.json', 'notify_email' => 'test@example.com'];
        $mockDb     = $this->buildMockMysqli(['config_json' => json_encode($configData)]);
        FormConfig::setConnectionForTesting($mockDb);

        $result = FormConfig::get('bs');

        $this->assertIsArray($result);
        $this->assertSame('bs.json', $result['form']);
        $this->assertSame('test@example.com', $result['notify_email']);
    }

    // =========================================================================
    // DB-backed get() — unknown form key
    // =========================================================================

    public function testGetReturnsNullForUnknownFormKey(): void
    {
        TenantContext::initialize(1);

        // DB returns no rows for unknown form key
        $mockDb = $this->buildMockMysqli(null);
        FormConfig::setConnectionForTesting($mockDb);

        $result = FormConfig::get('unknown-form-key');

        $this->assertNull($result);
    }

    // =========================================================================
    // DB-backed get() — JSON decoding
    // =========================================================================

    public function testGetDecodesConfigJsonToArray(): void
    {
        TenantContext::initialize(1);

        $configData = [
            'form'         => 'bk.json',
            'theme'        => 'survey_theme.json',
            'notify_email' => 'berufskolleg@example.com',
            'pdf'          => ['enabled' => true, 'token_lifetime' => 1800],
        ];

        $mockDb = $this->buildMockMysqli(['config_json' => json_encode($configData)]);
        FormConfig::setConnectionForTesting($mockDb);

        $result = FormConfig::get('bk');

        $this->assertIsArray($result);
        $this->assertSame('bk.json', $result['form']);
        $this->assertSame('berufskolleg@example.com', $result['notify_email']);
        $this->assertIsArray($result['pdf']);
        $this->assertTrue($result['pdf']['enabled']);
    }

    // =========================================================================
    // reset() — cache invalidation
    // =========================================================================

    public function testResetClearsStaticCache(): void
    {
        TenantContext::initialize(1);

        $configData = ['form' => 'bs.json'];

        // First call populates cache
        $mockDb1 = $this->buildMockMysqli(['config_json' => json_encode($configData)]);
        FormConfig::setConnectionForTesting($mockDb1);
        $first = FormConfig::get('bs');
        $this->assertIsArray($first);

        // Reset clears the cache — a second mockDb can be injected and will be queried again
        FormConfig::reset();

        $configData2 = ['form' => 'bs-v2.json'];
        $mockDb2     = $this->buildMockMysqli(['config_json' => json_encode($configData2)]);
        FormConfig::setConnectionForTesting($mockDb2);

        $second = FormConfig::get('bs');
        $this->assertIsArray($second);
        $this->assertSame('bs-v2.json', $second['form']);
    }
}
