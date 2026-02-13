<?php
declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\Anmeldung;
use App\Repositories\AnmeldungRepository;
use App\Services\StatusService;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

class StatusServiceTest extends TestCase
{
    private AnmeldungRepository $mockRepo;
    private StatusService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mockRepo = $this->createMock(AnmeldungRepository::class);
        $this->service = new StatusService($this->mockRepo);
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function makeAnmeldung(int $id = 1, string $status = 'neu'): Anmeldung
    {
        return new Anmeldung(
            id: $id,
            formular: 'bs',
            formularVersion: null,
            name: 'Max Mustermann',
            email: 'max@example.com',
            status: $status,
            data: null,
            createdAt: new DateTimeImmutable(),
            updatedAt: null,
            deleted: false,
            deletedAt: null,
        );
    }

    // =========================================================================
    // markAsExported
    // =========================================================================

    public function testMarkAsExportedReturnsFalseWhenNotFound(): void
    {
        $this->mockRepo->method('findById')->with(99)->willReturn(null);

        $result = $this->service->markAsExported(99);

        $this->assertFalse($result);
    }

    public function testMarkAsExportedUpdatesStatusWhenNeu(): void
    {
        $anmeldung = $this->makeAnmeldung(1, 'neu');

        $this->mockRepo->method('findById')->with(1)->willReturn($anmeldung);
        $this->mockRepo->expects($this->once())
            ->method('updateStatus')
            ->with(1, 'exportiert')
            ->willReturn(true);

        $result = $this->service->markAsExported(1);

        $this->assertTrue($result);
    }

    public function testMarkAsExportedSkipsUpdateWhenAlreadyExported(): void
    {
        $anmeldung = $this->makeAnmeldung(1, 'exportiert');

        $this->mockRepo->method('findById')->with(1)->willReturn($anmeldung);
        $this->mockRepo->expects($this->never())->method('updateStatus');

        $result = $this->service->markAsExported(1);

        $this->assertTrue($result); // returns true: already processed
    }

    public function testMarkAsExportedSkipsUpdateWhenAkzeptiert(): void
    {
        $anmeldung = $this->makeAnmeldung(1, 'akzeptiert');

        $this->mockRepo->method('findById')->willReturn($anmeldung);
        $this->mockRepo->expects($this->never())->method('updateStatus');

        $this->assertTrue($this->service->markAsExported(1));
    }

    // =========================================================================
    // markMultipleAsExported
    // =========================================================================

    public function testMarkMultipleAsExportedReturnsCountOfSuccessful(): void
    {
        $this->mockRepo->method('findById')->willReturnMap([
            [1, $this->makeAnmeldung(1, 'neu')],
            [2, $this->makeAnmeldung(2, 'neu')],
            [3, null],  // not found → markAsExported returns false
        ]);
        $this->mockRepo->method('updateStatus')->willReturn(true);

        $count = $this->service->markMultipleAsExported([1, 2, 3]);

        $this->assertSame(2, $count);
    }

    public function testMarkMultipleAsExportedReturnsZeroForEmptyArray(): void
    {
        $this->mockRepo->expects($this->never())->method('findById');

        $count = $this->service->markMultipleAsExported([]);

        $this->assertSame(0, $count);
    }

    // =========================================================================
    // archive
    // =========================================================================

    public function testArchiveDelegatesToRepository(): void
    {
        $this->mockRepo->expects($this->once())
            ->method('updateStatus')
            ->with(5, 'archiviert')
            ->willReturn(true);

        $result = $this->service->archive(5);

        $this->assertTrue($result);
    }

    public function testArchiveReturnsFalseOnRepositoryFailure(): void
    {
        $this->mockRepo->method('updateStatus')->willReturn(false);

        $this->assertFalse($this->service->archive(5));
    }

    // =========================================================================
    // bulkArchive
    // =========================================================================

    public function testBulkArchiveDelegatesToRepository(): void
    {
        $this->mockRepo->expects($this->once())
            ->method('bulkUpdateStatus')
            ->with([1, 2, 3], 'archiviert')
            ->willReturn(3);

        $count = $this->service->bulkArchive([1, 2, 3]);

        $this->assertSame(3, $count);
    }

    // =========================================================================
    // delete / bulkDelete
    // =========================================================================

    public function testDeleteDelegatesToRepository(): void
    {
        $this->mockRepo->expects($this->once())
            ->method('softDelete')
            ->with(7)
            ->willReturn(true);

        $result = $this->service->delete(7);

        $this->assertTrue($result);
    }

    public function testBulkDeleteDelegatesToRepository(): void
    {
        $this->mockRepo->expects($this->once())
            ->method('bulkSoftDelete')
            ->with([4, 5, 6])
            ->willReturn(3);

        $count = $this->service->bulkDelete([4, 5, 6]);

        $this->assertSame(3, $count);
    }

    // =========================================================================
    // updateStatus
    // =========================================================================

    public function testUpdateStatusAcceptsValidStatus(): void
    {
        $this->mockRepo->expects($this->once())
            ->method('updateStatus')
            ->with(1, 'akzeptiert')
            ->willReturn(true);

        $result = $this->service->updateStatus(1, 'akzeptiert');

        $this->assertTrue($result);
    }

    public function testUpdateStatusThrowsForInvalidStatus(): void
    {
        $this->mockRepo->expects($this->never())->method('updateStatus');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid status');

        $this->service->updateStatus(1, 'invented_status');
    }

    public function testUpdateStatusAcceptsAllDefinedStatuses(): void
    {
        $this->mockRepo->method('updateStatus')->willReturn(true);

        $validStatuses = ['neu', 'exportiert', 'in_bearbeitung', 'akzeptiert', 'abgelehnt', 'archiviert'];

        foreach ($validStatuses as $status) {
            // Must not throw
            $result = $this->service->updateStatus(1, $status);
            $this->assertTrue($result);
        }
    }

    // =========================================================================
    // getStatistics
    // =========================================================================

    public function testGetStatisticsDelegatesToRepository(): void
    {
        $expected = ['neu' => 5, 'exportiert' => 3, 'archiviert' => 1];

        $this->mockRepo->expects($this->once())
            ->method('getStatistics')
            ->willReturn($expected);

        $result = $this->service->getStatistics();

        $this->assertSame($expected, $result);
    }
}
