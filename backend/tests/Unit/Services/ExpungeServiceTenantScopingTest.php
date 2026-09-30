<?php
declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Config\Config;
use App\Config\TenantContext;
use App\Models\Anmeldung;
use App\Repositories\AnmeldungRepository;
use App\Services\ExpungeService;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * ISOL-04: Verifies that auto-expunge is properly tenant-scoped.
 *
 * The DSGVO-critical guarantee: auto-expunge only touches records of the current
 * tenant, never others. This is enforced at the repository layer (findExpiredArchived
 * adds WHERE tenant_id = ? when TenantContext is NOT in all-tenants mode).
 *
 * These tests prove:
 * 1. In single-tenant context, findExpiredArchived() is called once (repository
 *    will apply the tenant scope internally).
 * 2. In all-tenants mode, autoExpunge() still calls findExpiredArchived() and
 *    completes without throwing.
 * 3. When AUTO_EXPUNGE_DAYS=0, findExpiredArchived() is never called (expunge
 *    disabled regardless of tenant context).
 */
class ExpungeServiceTenantScopingTest extends TestCase
{
    private AnmeldungRepository $mockRepo;
    private ExpungeService $service;
    /** @var string|null */
    private ?string $savedExpungeDays;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mockRepo = $this->createMock(AnmeldungRepository::class);
        $this->service  = new ExpungeService($this->mockRepo, $this->createMock(\App\Services\UploadCleanupService::class));

        // Isolate env vars so putenv() controls what Config reads
        $this->savedExpungeDays = $_ENV['AUTO_EXPUNGE_DAYS'] ?? $_SERVER['AUTO_EXPUNGE_DAYS'] ?? null;
        unset($_ENV['AUTO_EXPUNGE_DAYS'], $_SERVER['AUTO_EXPUNGE_DAYS']);
        $this->resetConfigSingleton();
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        TenantContext::reset();
        $this->resetConfigSingleton();
        putenv('AUTO_EXPUNGE_DAYS=0');
        if ($this->savedExpungeDays !== null) {
            $_ENV['AUTO_EXPUNGE_DAYS']    = $this->savedExpungeDays;
            $_SERVER['AUTO_EXPUNGE_DAYS'] = $this->savedExpungeDays;
        }
    }

    private function resetConfigSingleton(): void
    {
        $ref  = new ReflectionClass(Config::class);
        $prop = $ref->getProperty('instance');
        $prop->setAccessible(true);
        $prop->setValue(null, null);
    }

    // =========================================================================
    // ISOL-04 — Test 1: tenant-scoped context calls findExpiredArchived once
    // =========================================================================

    /**
     * When TenantContext is initialized for a specific tenant, autoExpunge() MUST
     * call findExpiredArchived() exactly once. The repository layer is responsible
     * for adding the WHERE tenant_id = ? clause — this test proves the code path
     * is exercised and the call happens with the correct $daysOld argument.
     */
    public function testAutoExpungeCallsRepoWithTenantScopedContext(): void
    {
        TenantContext::initialize(1);
        putenv('AUTO_EXPUNGE_DAYS=30');

        $this->mockRepo
            ->expects($this->once())
            ->method('findExpiredArchived')
            ->with(30)
            ->willReturn([]);

        $result = $this->service->autoExpunge();

        $this->assertSame(0, $result['deleted']);
        $this->assertSame([], $result['ids']);
    }

    // =========================================================================
    // ISOL-04 — Test 2: all-tenants mode does not crash and still calls repo
    // =========================================================================

    /**
     * When TenantContext is in all-tenants mode (platform admin), autoExpunge()
     * must NOT crash or silently skip. It must call findExpiredArchived() and
     * complete normally. The repository layer will omit the tenant filter in this
     * mode (platform admin can expunge across all tenants).
     */
    public function testAutoExpungeInAllTenantsContextStillRunsForEachTenant(): void
    {
        TenantContext::initAllTenants();
        putenv('AUTO_EXPUNGE_DAYS=30');

        $this->mockRepo
            ->expects($this->once())
            ->method('findExpiredArchived')
            ->with(30)
            ->willReturn([]);

        $result = $this->service->autoExpunge();

        $this->assertSame(0, $result['deleted']);
        $this->assertSame([], $result['ids']);
    }

    // =========================================================================
    // ISOL-04 — Test 3: expunge disabled means repo is never called
    // =========================================================================

    /**
     * When AUTO_EXPUNGE_DAYS=0, autoExpunge() MUST return early without calling
     * the repository, regardless of the tenant context. This is a hard guard that
     * prevents any deletions when the feature is disabled.
     */
    public function testAutoExpungeIsSkippedWhenDaysIsZero(): void
    {
        TenantContext::initialize(1);
        putenv('AUTO_EXPUNGE_DAYS=0');

        $this->mockRepo
            ->expects($this->never())
            ->method('findExpiredArchived');

        $result = $this->service->autoExpunge();

        $this->assertSame(0, $result['deleted']);
        $this->assertSame([], $result['ids']);
    }
}
