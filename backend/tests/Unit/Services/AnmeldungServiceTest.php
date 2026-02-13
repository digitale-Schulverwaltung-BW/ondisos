<?php
declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Repositories\AnmeldungRepository;
use App\Services\AnmeldungService;
use PHPUnit\Framework\TestCase;

class AnmeldungServiceTest extends TestCase
{
    private AnmeldungRepository $mockRepo;
    private AnmeldungService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mockRepo = $this->createMock(AnmeldungRepository::class);
        $this->service = new AnmeldungService($this->mockRepo);
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function repoReturns(int $total = 0, array $items = []): void
    {
        $this->mockRepo->method('findPaginated')->willReturn([
            'total' => $total,
            'items' => $items,
        ]);
    }

    // =========================================================================
    // getPaginatedAnmeldungen – perPage validation
    // =========================================================================

    public function testGetPaginatedUsesDefaultPerPageForInvalidValue(): void
    {
        $this->repoReturns(0);

        $this->mockRepo->expects($this->once())
            ->method('findPaginated')
            ->with(
                formularFilter: null,
                statusFilter: null,
                limit: 25,   // default
                offset: 0
            )
            ->willReturn(['total' => 0, 'items' => []]);

        $result = $this->service->getPaginatedAnmeldungen(perPage: 99);

        $this->assertSame(25, $result['pagination']['perPage']);
    }

    public function testGetPaginatedAcceptsAllAllowedPerPageValues(): void
    {
        foreach ([10, 25, 50, 100] as $perPage) {
            $this->mockRepo->method('findPaginated')->willReturn(['total' => 0, 'items' => []]);
            $service = new AnmeldungService($this->mockRepo);

            $result = $service->getPaginatedAnmeldungen(perPage: $perPage);

            $this->assertSame($perPage, $result['pagination']['perPage'], "perPage=$perPage should be accepted");
        }
    }

    public function testGetPaginatedUsesDefaultPerPageForZero(): void
    {
        $this->repoReturns(0);

        $result = $this->service->getPaginatedAnmeldungen(perPage: 0);

        $this->assertSame(25, $result['pagination']['perPage']);
    }

    // =========================================================================
    // getPaginatedAnmeldungen – page clamping
    // =========================================================================

    public function testGetPaginatedClampsPageToMinimumOne(): void
    {
        $this->mockRepo->expects($this->once())
            ->method('findPaginated')
            ->with(
                formularFilter: null,
                statusFilter: null,
                limit: 25,
                offset: 0    // page 1 → offset 0
            )
            ->willReturn(['total' => 0, 'items' => []]);

        $result = $this->service->getPaginatedAnmeldungen(page: -5);

        $this->assertSame(1, $result['pagination']['page']);
    }

    public function testGetPaginatedCalculatesOffsetCorrectly(): void
    {
        // page 3, perPage 10 → offset = (3-1)*10 = 20
        $this->mockRepo->expects($this->once())
            ->method('findPaginated')
            ->with(
                formularFilter: null,
                statusFilter: null,
                limit: 10,
                offset: 20
            )
            ->willReturn(['total' => 100, 'items' => []]);

        $this->service->getPaginatedAnmeldungen(page: 3, perPage: 10);
    }

    // =========================================================================
    // getPaginatedAnmeldungen – filter sanitizing
    // =========================================================================

    public function testGetPaginatedConvertsEmptyStringFilterToNull(): void
    {
        $this->mockRepo->expects($this->once())
            ->method('findPaginated')
            ->with(
                formularFilter: null,
                statusFilter: null,
                limit: 25,
                offset: 0
            )
            ->willReturn(['total' => 0, 'items' => []]);

        $this->service->getPaginatedAnmeldungen(formularFilter: '', statusFilter: '');
    }

    public function testGetPaginatedPassesNonEmptyFiltersThrough(): void
    {
        $this->mockRepo->expects($this->once())
            ->method('findPaginated')
            ->with(
                formularFilter: 'bs',
                statusFilter: 'neu',
                limit: 25,
                offset: 0
            )
            ->willReturn(['total' => 0, 'items' => []]);

        $this->service->getPaginatedAnmeldungen(formularFilter: 'bs', statusFilter: 'neu');
    }

    // =========================================================================
    // getPaginatedAnmeldungen – formular name validation
    // =========================================================================

    public function testGetPaginatedThrowsForInvalidFormularName(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->service->getPaginatedAnmeldungen(formularFilter: 'bs; DROP TABLE');
    }

    public function testGetPaginatedThrowsForFormularNameTooLong(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->service->getPaginatedAnmeldungen(formularFilter: str_repeat('a', 51));
    }

    // =========================================================================
    // getPaginatedAnmeldungen – totalPages calculation
    // =========================================================================

    public function testGetPaginatedCalculatesTotalPagesCorrectly(): void
    {
        $this->repoReturns(total: 100);

        $result = $this->service->getPaginatedAnmeldungen(perPage: 10);

        $this->assertSame(10, $result['pagination']['totalPages']);
    }

    public function testGetPaginatedRoundsUpTotalPages(): void
    {
        $this->repoReturns(total: 11);

        $result = $this->service->getPaginatedAnmeldungen(perPage: 10);

        $this->assertSame(2, $result['pagination']['totalPages']);
    }

    public function testGetPaginatedReturnsAtLeastOnePage(): void
    {
        $this->repoReturns(total: 0);

        $result = $this->service->getPaginatedAnmeldungen();

        $this->assertSame(1, $result['pagination']['totalPages']);
    }

    // =========================================================================
    // getPaginatedAnmeldungen – return structure
    // =========================================================================

    public function testGetPaginatedReturnsCorrectStructure(): void
    {
        $this->repoReturns(total: 5);

        $result = $this->service->getPaginatedAnmeldungen(page: 1, perPage: 25);

        $this->assertArrayHasKey('items', $result);
        $this->assertArrayHasKey('pagination', $result);
        $this->assertArrayHasKey('page', $result['pagination']);
        $this->assertArrayHasKey('perPage', $result['pagination']);
        $this->assertArrayHasKey('totalPages', $result['pagination']);
        $this->assertArrayHasKey('totalItems', $result['pagination']);
    }

    public function testGetPaginatedReturnsTotalItems(): void
    {
        $this->repoReturns(total: 42);

        $result = $this->service->getPaginatedAnmeldungen();

        $this->assertSame(42, $result['pagination']['totalItems']);
    }

    // =========================================================================
    // getAvailableForms
    // =========================================================================

    public function testGetAvailableFormsDelegatesToRepository(): void
    {
        $this->mockRepo->expects($this->once())
            ->method('getAllFormNames')
            ->willReturn(['bs', 'bk']);

        $forms = $this->service->getAvailableForms();

        $this->assertSame(['bs', 'bk'], $forms);
    }

    public function testGetAvailableFormsReturnsEmptyArrayWhenNoForms(): void
    {
        $this->mockRepo->method('getAllFormNames')->willReturn([]);

        $this->assertSame([], $this->service->getAvailableForms());
    }

    // =========================================================================
    // getAllowedPerPageValues
    // =========================================================================

    public function testGetAllowedPerPageValuesReturnsExpectedValues(): void
    {
        $values = $this->service->getAllowedPerPageValues();

        $this->assertSame([10, 25, 50, 100], $values);
    }

    public function testGetAllowedPerPageValuesContainsDefaultPerPage(): void
    {
        $values = $this->service->getAllowedPerPageValues();

        // Default is 25 — must be in the allowed list
        $this->assertContains(25, $values);
    }
}
