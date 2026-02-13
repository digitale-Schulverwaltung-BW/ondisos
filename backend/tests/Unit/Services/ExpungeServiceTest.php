<?php
declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Config\Config;
use App\Models\Anmeldung;
use App\Repositories\AnmeldungRepository;
use App\Services\ExpungeService;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

class ExpungeServiceTest extends TestCase
{
    private AnmeldungRepository $mockRepo;
    private ExpungeService $service;
    /** @var string|null Saved value of $_ENV['AUTO_EXPUNGE_DAYS'] before each test */
    private ?string $savedExpungeDays;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mockRepo = $this->createMock(AnmeldungRepository::class);
        $this->service = new ExpungeService($this->mockRepo);
        // Isolate $_ENV and $_SERVER so putenv() controls what Config reads
        $this->savedExpungeDays = $_ENV['AUTO_EXPUNGE_DAYS'] ?? $_SERVER['AUTO_EXPUNGE_DAYS'] ?? null;
        unset($_ENV['AUTO_EXPUNGE_DAYS'], $_SERVER['AUTO_EXPUNGE_DAYS']);
        $this->resetConfigSingleton();
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        $this->resetConfigSingleton();
        // Restore env to disabled state (default in test env)
        putenv('AUTO_EXPUNGE_DAYS=0');
        // Restore $_ENV and $_SERVER isolation
        if ($this->savedExpungeDays !== null) {
            $_ENV['AUTO_EXPUNGE_DAYS']    = $this->savedExpungeDays;
            $_SERVER['AUTO_EXPUNGE_DAYS'] = $this->savedExpungeDays;
        }
    }

    /**
     * Reset the Config singleton so putenv() changes take effect in the next call.
     */
    private function resetConfigSingleton(): void
    {
        $ref = new ReflectionClass(Config::class);
        $prop = $ref->getProperty('instance');
        $prop->setAccessible(true);
        $prop->setValue(null, null);
    }

    private function makeAnmeldung(int $id, bool $deleted = false): Anmeldung
    {
        return new Anmeldung(
            id: $id,
            formular: 'bs',
            formularVersion: null,
            name: 'Max Mustermann',
            email: 'max@example.com',
            status: 'archiviert',
            data: null,
            createdAt: new DateTimeImmutable('-100 days'),
            updatedAt: new DateTimeImmutable('-91 days'),
            deleted: $deleted,
            deletedAt: $deleted ? new DateTimeImmutable('-91 days') : null,
        );
    }

    // =========================================================================
    // autoExpunge - disabled
    // =========================================================================

    public function testAutoExpungeReturnsZeroWhenDisabled(): void
    {
        putenv('AUTO_EXPUNGE_DAYS=0');

        $this->mockRepo->expects($this->never())->method('findExpiredArchived');

        $result = $this->service->autoExpunge();

        $this->assertSame(0, $result['deleted']);
        $this->assertSame([], $result['ids']);
    }

    public function testAutoExpungeReturnsZeroWhenNegativeDays(): void
    {
        putenv('AUTO_EXPUNGE_DAYS=-1');

        $this->mockRepo->expects($this->never())->method('findExpiredArchived');

        $result = $this->service->autoExpunge();

        $this->assertSame(0, $result['deleted']);
    }

    // =========================================================================
    // autoExpunge - nothing to delete
    // =========================================================================

    public function testAutoExpungeReturnsZeroWhenNoExpiredEntries(): void
    {
        putenv('AUTO_EXPUNGE_DAYS=90');

        $this->mockRepo->method('findExpiredArchived')->with(90)->willReturn([]);
        $this->mockRepo->expects($this->never())->method('hardDelete');

        $result = $this->service->autoExpunge();

        $this->assertSame(0, $result['deleted']);
        $this->assertSame([], $result['ids']);
    }

    // =========================================================================
    // autoExpunge - entries present
    // =========================================================================

    public function testAutoExpungeHardDeletesExpiredEntries(): void
    {
        putenv('AUTO_EXPUNGE_DAYS=90');

        $entries = [
            $this->makeAnmeldung(10),
            $this->makeAnmeldung(20),
        ];

        $this->mockRepo->method('findExpiredArchived')->willReturn($entries);
        $this->mockRepo->method('softDelete')->willReturn(true);
        $this->mockRepo->method('hardDelete')->willReturn(true);

        $result = $this->service->autoExpunge();

        $this->assertSame(2, $result['deleted']);
        $this->assertSame([10, 20], $result['ids']);
    }

    public function testAutoExpungeSoftDeletesBeforeHardDelete(): void
    {
        putenv('AUTO_EXPUNGE_DAYS=90');

        $entry = $this->makeAnmeldung(10, deleted: false);

        $this->mockRepo->method('findExpiredArchived')->willReturn([$entry]);
        $this->mockRepo->expects($this->once())->method('softDelete')->with(10);
        $this->mockRepo->method('hardDelete')->willReturn(true);

        $this->service->autoExpunge();
    }

    public function testAutoExpungeSkipsSoftDeleteWhenAlreadyDeleted(): void
    {
        putenv('AUTO_EXPUNGE_DAYS=90');

        $entry = $this->makeAnmeldung(10, deleted: true);

        $this->mockRepo->method('findExpiredArchived')->willReturn([$entry]);
        $this->mockRepo->expects($this->never())->method('softDelete');
        $this->mockRepo->method('hardDelete')->willReturn(true);

        $this->service->autoExpunge();
    }

    public function testAutoExpungeCountsOnlySuccessfulHardDeletes(): void
    {
        putenv('AUTO_EXPUNGE_DAYS=90');

        $entries = [
            $this->makeAnmeldung(10),
            $this->makeAnmeldung(20),
            $this->makeAnmeldung(30),
        ];

        $this->mockRepo->method('findExpiredArchived')->willReturn($entries);
        $this->mockRepo->method('softDelete')->willReturn(true);
        // Only entry 10 and 30 succeed
        $this->mockRepo->method('hardDelete')->willReturnMap([
            [10, true],
            [20, false],
            [30, true],
        ]);

        $result = $this->service->autoExpunge();

        $this->assertSame(2, $result['deleted']);
        $this->assertSame([10, 30], $result['ids']);
    }

    // =========================================================================
    // previewExpunge
    // =========================================================================

    public function testPreviewExpungeReturnsZeroWhenDisabled(): void
    {
        putenv('AUTO_EXPUNGE_DAYS=0');

        $this->mockRepo->expects($this->never())->method('findExpiredArchived');

        $result = $this->service->previewExpunge();

        $this->assertSame(0, $result['count']);
        $this->assertNull($result['oldest']);
        $this->assertNull($result['newest']);
    }

    public function testPreviewExpungeReturnsZeroWhenNothingExpired(): void
    {
        putenv('AUTO_EXPUNGE_DAYS=90');

        $this->mockRepo->method('findExpiredArchived')->willReturn([]);

        $result = $this->service->previewExpunge();

        $this->assertSame(0, $result['count']);
        $this->assertNull($result['oldest']);
        $this->assertNull($result['newest']);
    }

    public function testPreviewExpungeReturnsCorrectCount(): void
    {
        putenv('AUTO_EXPUNGE_DAYS=90');

        $entries = [
            $this->makeAnmeldung(1),
            $this->makeAnmeldung(2),
            $this->makeAnmeldung(3),
        ];

        $this->mockRepo->method('findExpiredArchived')->willReturn($entries);

        $result = $this->service->previewExpunge();

        $this->assertSame(3, $result['count']);
    }

    public function testPreviewExpungeReturnsOldestAndNewest(): void
    {
        putenv('AUTO_EXPUNGE_DAYS=90');

        $old = new Anmeldung(
            id: 1, formular: 'bs', formularVersion: null,
            name: 'A', email: 'a@b.com', status: 'archiviert', data: null,
            createdAt: new DateTimeImmutable('-200 days'),
            updatedAt: new DateTimeImmutable('-150 days'),
            deleted: false, deletedAt: null,
        );
        $new = new Anmeldung(
            id: 2, formular: 'bs', formularVersion: null,
            name: 'B', email: 'b@c.com', status: 'archiviert', data: null,
            createdAt: new DateTimeImmutable('-100 days'),
            updatedAt: new DateTimeImmutable('-95 days'),
            deleted: false, deletedAt: null,
        );

        $this->mockRepo->method('findExpiredArchived')->willReturn([$old, $new]);

        $result = $this->service->previewExpunge();

        $this->assertInstanceOf(DateTimeImmutable::class, $result['oldest']);
        $this->assertInstanceOf(DateTimeImmutable::class, $result['newest']);
        // oldest updatedAt < newest updatedAt
        $this->assertLessThan($result['newest'], $result['oldest']);
    }

    public function testPreviewExpungeFallsBackToCreatedAtWhenUpdatedAtNull(): void
    {
        putenv('AUTO_EXPUNGE_DAYS=90');

        $entry = new Anmeldung(
            id: 1, formular: 'bs', formularVersion: null,
            name: 'A', email: 'a@b.com', status: 'archiviert', data: null,
            createdAt: new DateTimeImmutable('-100 days'),
            updatedAt: null,  // no updatedAt
            deleted: false, deletedAt: null,
        );

        $this->mockRepo->method('findExpiredArchived')->willReturn([$entry]);

        $result = $this->service->previewExpunge();

        $this->assertSame(1, $result['count']);
        $this->assertInstanceOf(DateTimeImmutable::class, $result['oldest']);
    }

    // =========================================================================
    // manualExpunge
    // =========================================================================

    public function testManualExpungeHardDeletesAllIds(): void
    {
        $this->mockRepo->expects($this->exactly(3))
            ->method('hardDelete')
            ->willReturn(true);

        $count = $this->service->manualExpunge([1, 2, 3]);

        $this->assertSame(3, $count);
    }

    public function testManualExpungeReturnsZeroForEmptyArray(): void
    {
        $this->mockRepo->expects($this->never())->method('hardDelete');

        $count = $this->service->manualExpunge([]);

        $this->assertSame(0, $count);
    }

    public function testManualExpungeCountsOnlySuccessfulDeletes(): void
    {
        $this->mockRepo->method('hardDelete')->willReturnMap([
            [1, true],
            [2, false],
            [3, true],
        ]);

        $count = $this->service->manualExpunge([1, 2, 3]);

        $this->assertSame(2, $count);
    }
}
