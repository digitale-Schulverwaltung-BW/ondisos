<?php
declare(strict_types=1);

namespace Tests\Unit\Controllers;

use App\Controllers\BulkActionsController;
use App\Services\StatusService;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class BulkActionsControllerTest extends TestCase
{
    private array $serverBackup;
    private array $postBackup;

    protected function setUp(): void
    {
        parent::setUp();
        $this->serverBackup = $_SERVER;
        $this->postBackup = $_POST;
        $_SERVER['REQUEST_METHOD'] = 'POST';
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->serverBackup;
        $_POST = $this->postBackup;
        parent::tearDown();
    }

    /** @return array<string, array{string}> */
    public static function statusActions(): array
    {
        return [
            'in bearbeitung' => ['in_bearbeitung'],
            'akzeptiert'     => ['akzeptiert'],
            'abgelehnt'      => ['abgelehnt'],
        ];
    }

    /**
     * @dataProvider statusActions
     */
    public function testStatusActionsSetTheStatusOfTheSelectedEntries(string $action): void
    {
        $service = $this->createMock(StatusService::class);
        $service->expects($this->once())->method('bulkUpdateStatus')->with([4, 7], $action)->willReturn(2);
        $service->expects($this->never())->method('bulkArchive');
        $service->expects($this->never())->method('bulkDelete');

        $_POST = ['action' => $action, 'ids' => ['4', '7', 'x', '-3']];

        $result = (new BulkActionsController($service))->handle();

        $this->assertTrue($result['success']);
        $this->assertSame($action, $result['action']);
        $this->assertSame(2, $result['count']);
    }

    public function testArchiveStillUsesTheArchiveMethod(): void
    {
        $service = $this->createMock(StatusService::class);
        $service->expects($this->once())->method('bulkArchive')->willReturn(1);
        $service->expects($this->never())->method('bulkUpdateStatus');

        $_POST = ['action' => 'archive', 'ids' => ['1']];

        $this->assertSame(1, (new BulkActionsController($service))->handle()['count']);
    }

    /**
     * @dataProvider invalidActions
     */
    public function testUnknownActionsAreRejected(string $action): void
    {
        $service = $this->createMock(StatusService::class);
        $service->expects($this->never())->method('bulkUpdateStatus');

        $_POST = ['action' => $action, 'ids' => ['1']];

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid action');
        (new BulkActionsController($service))->handle();
    }

    /** @return array<string, array{string}> */
    public static function invalidActions(): array
    {
        return [
            'leer'                 => [''],
            'neu ist keine Aktion' => ['neu'],
            'exportiert'           => ['exportiert'],
            'archiviert'           => ['archiviert'],
            'beliebiger Text'      => ['drop_table'],
        ];
    }

    public function testEmptySelectionIsRejected(): void
    {
        $_POST = ['action' => 'akzeptiert', 'ids' => []];

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('No items selected');
        (new BulkActionsController($this->createMock(StatusService::class)))->handle();
    }

    public function testActionLabelsReadAsPartOfTheSuccessMessage(): void
    {
        $this->assertSame('Archiviert', BulkActionsController::getActionLabel('archive'));
        $this->assertSame('Gelöscht', BulkActionsController::getActionLabel('delete'));
        $this->assertSame('auf „Akzeptiert“ gesetzt', BulkActionsController::getActionLabel('akzeptiert'));
        $this->assertSame('auf „In Bearbeitung“ gesetzt', BulkActionsController::getActionLabel('in_bearbeitung'));
        $this->assertSame('auf „Abgelehnt“ gesetzt', BulkActionsController::getActionLabel('abgelehnt'));
    }
}
