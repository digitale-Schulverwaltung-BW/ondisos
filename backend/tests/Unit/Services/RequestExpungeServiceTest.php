<?php
declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Config\Config;
use App\Services\ExpungeService;
use App\Services\RequestExpungeService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

class RequestExpungeServiceTest extends TestCase
{
    private ExpungeService $mockExpunge;
    private RequestExpungeService $service;

    /** Path to the real cache file used by the service */
    private string $cacheFile;
    /** Saved content of cache file before test (null = didn't exist) */
    private ?string $savedCacheContent = null;
    /** @var string|null Saved value of $_ENV['AUTO_EXPUNGE_DAYS'] before each test */
    private ?string $savedExpungeDays;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mockExpunge = $this->createMock(ExpungeService::class);
        $this->service = new RequestExpungeService($this->mockExpunge);

        // Resolve the actual cache file path via Reflection
        $ref = new ReflectionClass(RequestExpungeService::class);
        $this->cacheFile = $ref->getConstant('CACHE_FILE');

        // Save and remove any existing cache file
        if (file_exists($this->cacheFile)) {
            $this->savedCacheContent = file_get_contents($this->cacheFile);
            unlink($this->cacheFile);
        }

        // Isolate $_ENV and $_SERVER so putenv() controls what Config reads
        $this->savedExpungeDays = $_ENV['AUTO_EXPUNGE_DAYS'] ?? $_SERVER['AUTO_EXPUNGE_DAYS'] ?? null;
        unset($_ENV['AUTO_EXPUNGE_DAYS'], $_SERVER['AUTO_EXPUNGE_DAYS']);

        $this->resetConfigSingleton();
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        // Restore cache file to original state
        if ($this->savedCacheContent !== null) {
            file_put_contents($this->cacheFile, $this->savedCacheContent);
        } elseif (file_exists($this->cacheFile)) {
            unlink($this->cacheFile);
        }

        $this->resetConfigSingleton();
        putenv('AUTO_EXPUNGE_DAYS=0');

        // Restore $_ENV and $_SERVER isolation
        if ($this->savedExpungeDays !== null) {
            $_ENV['AUTO_EXPUNGE_DAYS']    = $this->savedExpungeDays;
            $_SERVER['AUTO_EXPUNGE_DAYS'] = $this->savedExpungeDays;
        }
    }

    private function resetConfigSingleton(): void
    {
        $ref = new ReflectionClass(Config::class);
        $prop = $ref->getProperty('instance');
        $prop->setAccessible(true);
        $prop->setValue(null, null);
    }

    private function writeCacheFile(int $timestamp): void
    {
        $dir = dirname($this->cacheFile);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        file_put_contents($this->cacheFile, (string)$timestamp);
    }

    // =========================================================================
    // checkAndRun – disabled
    // =========================================================================

    public function testCheckAndRunReturnsNullWhenDisabled(): void
    {
        putenv('AUTO_EXPUNGE_DAYS=0');

        $this->mockExpunge->expects($this->never())->method('autoExpunge');

        $result = $this->service->checkAndRun();

        $this->assertNull($result);
    }

    public function testCheckAndRunReturnsNullForNegativeDays(): void
    {
        putenv('AUTO_EXPUNGE_DAYS=-1');

        $this->mockExpunge->expects($this->never())->method('autoExpunge');

        $result = $this->service->checkAndRun();

        $this->assertNull($result);
    }

    // =========================================================================
    // checkAndRun – throttling
    // =========================================================================

    public function testCheckAndRunReturnsNullWhenRunRecently(): void
    {
        putenv('AUTO_EXPUNGE_DAYS=90');
        // Write a very recent timestamp (1 minute ago)
        $this->writeCacheFile(time() - 60);

        $this->mockExpunge->expects($this->never())->method('autoExpunge');

        $result = $this->service->checkAndRun();

        $this->assertNull($result);
    }

    public function testCheckAndRunExecutesWhenNoCacheFileExists(): void
    {
        putenv('AUTO_EXPUNGE_DAYS=90');
        // No cache file → shouldRun returns true

        $this->mockExpunge->expects($this->once())
            ->method('autoExpunge')
            ->willReturn(['deleted' => 0, 'ids' => []]);

        $result = $this->service->checkAndRun();

        $this->assertIsArray($result);
        $this->assertSame(0, $result['deleted']);
    }

    public function testCheckAndRunExecutesWhenCacheFileIsOld(): void
    {
        putenv('AUTO_EXPUNGE_DAYS=90');
        // Write a timestamp 7 hours ago (> 6h interval)
        $this->writeCacheFile(time() - 7 * 3600);

        $this->mockExpunge->expects($this->once())
            ->method('autoExpunge')
            ->willReturn(['deleted' => 2, 'ids' => [10, 20]]);

        $result = $this->service->checkAndRun();

        $this->assertSame(2, $result['deleted']);
    }

    // =========================================================================
    // checkAndRun – cache update & return value
    // =========================================================================

    public function testCheckAndRunUpdatesCacheFileAfterRun(): void
    {
        putenv('AUTO_EXPUNGE_DAYS=90');
        $before = time();

        $this->mockExpunge->method('autoExpunge')
            ->willReturn(['deleted' => 0, 'ids' => []]);

        $this->service->checkAndRun();

        $this->assertFileExists($this->cacheFile);
        $written = (int)file_get_contents($this->cacheFile);
        $this->assertGreaterThanOrEqual($before, $written);
    }

    public function testCheckAndRunReturnsExpungeResult(): void
    {
        putenv('AUTO_EXPUNGE_DAYS=90');

        $this->mockExpunge->method('autoExpunge')
            ->willReturn(['deleted' => 3, 'ids' => [1, 2, 3]]);

        $result = $this->service->checkAndRun();

        $this->assertSame(3, $result['deleted']);
        $this->assertSame([1, 2, 3], $result['ids']);
    }

    // =========================================================================
    // checkAndRun – exception handling
    // =========================================================================

    public function testCheckAndRunReturnsNullOnExpungeException(): void
    {
        putenv('AUTO_EXPUNGE_DAYS=90');

        $this->mockExpunge->method('autoExpunge')
            ->willThrowException(new \RuntimeException('DB error'));

        // Should NOT throw — returns null gracefully
        $result = $this->service->checkAndRun();

        $this->assertNull($result);
    }

    // =========================================================================
    // getLastRunInfo
    // =========================================================================

    public function testGetLastRunInfoReturnsNullsWhenNoCacheFile(): void
    {
        $info = $this->service->getLastRunInfo();

        $this->assertNull($info['lastRun']);
        $this->assertNull($info['nextRun']);
        $this->assertTrue($info['canRunNow']);
    }

    public function testGetLastRunInfoReturnsDatesWhenCacheFileExists(): void
    {
        $ts = time() - 3600; // 1 hour ago
        $this->writeCacheFile($ts);

        $info = $this->service->getLastRunInfo();

        $this->assertInstanceOf(\DateTimeImmutable::class, $info['lastRun']);
        $this->assertInstanceOf(\DateTimeImmutable::class, $info['nextRun']);
    }

    public function testGetLastRunInfoCanRunNowFalseWhenRecent(): void
    {
        $this->writeCacheFile(time() - 60); // 1 minute ago

        $info = $this->service->getLastRunInfo();

        $this->assertFalse($info['canRunNow']);
    }

    public function testGetLastRunInfoCanRunNowTrueWhenOld(): void
    {
        $this->writeCacheFile(time() - 7 * 3600); // 7 hours ago

        $info = $this->service->getLastRunInfo();

        $this->assertTrue($info['canRunNow']);
    }

    public function testGetLastRunInfoNextRunIsSixHoursAfterLastRun(): void
    {
        $ts = time() - 3600;
        $this->writeCacheFile($ts);

        $info = $this->service->getLastRunInfo();

        $expectedNext = $ts + 6 * 3600;
        $this->assertSame($expectedNext, $info['nextRun']->getTimestamp());
    }

    // =========================================================================
    // forceRun
    // =========================================================================

    public function testForceRunCallsAutoExpunge(): void
    {
        $this->mockExpunge->expects($this->once())
            ->method('autoExpunge')
            ->willReturn(['deleted' => 1, 'ids' => [5]]);

        $result = $this->service->forceRun();

        $this->assertSame(1, $result['deleted']);
    }

    public function testForceRunUpdatesCacheFile(): void
    {
        $before = time();

        $this->mockExpunge->method('autoExpunge')
            ->willReturn(['deleted' => 0, 'ids' => []]);

        $this->service->forceRun();

        $this->assertFileExists($this->cacheFile);
        $written = (int)file_get_contents($this->cacheFile);
        $this->assertGreaterThanOrEqual($before, $written);
    }

    public function testForceRunBypassesThrottling(): void
    {
        // Even with a very recent cache file, forceRun should still execute
        $this->writeCacheFile(time() - 60); // 1 minute ago

        $this->mockExpunge->expects($this->once())
            ->method('autoExpunge')
            ->willReturn(['deleted' => 0, 'ids' => []]);

        $this->service->forceRun();
    }
}
