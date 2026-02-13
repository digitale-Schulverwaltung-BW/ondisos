<?php
declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Config\Config;
use App\Services\ExportService;
use App\Services\StatusService;
use App\Repositories\AnmeldungRepository;
use App\Models\Anmeldung;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Tests for ExportService - especially SQL injection prevention
 */
class ExportServiceTest extends TestCase
{
    private AnmeldungRepository $mockRepository;
    private StatusService $mockStatusService;
    private ExportService $service;
    /** @var string|null Saved value of $_ENV['AUTO_MARK_AS_READ'] before each test */
    private ?string $savedAutoMark;

    protected function setUp(): void
    {
        parent::setUp();

        // Create mocks
        $this->mockRepository = $this->createMock(AnmeldungRepository::class);
        $this->mockStatusService = $this->createMock(StatusService::class);

        // Create service with mocked dependencies
        $this->service = new ExportService(
            $this->mockRepository,
            $this->mockStatusService
        );

        // Isolate $_ENV and $_SERVER so putenv() controls what Config reads
        $this->savedAutoMark = $_ENV['AUTO_MARK_AS_READ'] ?? $_SERVER['AUTO_MARK_AS_READ'] ?? null;
        unset($_ENV['AUTO_MARK_AS_READ'], $_SERVER['AUTO_MARK_AS_READ']);

        $this->resetConfigSingleton();
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        $this->resetConfigSingleton();
        putenv('AUTO_MARK_AS_READ=false');
        // Restore $_ENV and $_SERVER isolation
        if ($this->savedAutoMark !== null) {
            $_ENV['AUTO_MARK_AS_READ']    = $this->savedAutoMark;
            $_SERVER['AUTO_MARK_AS_READ'] = $this->savedAutoMark;
        }
    }

    private function resetConfigSingleton(): void
    {
        $ref = new ReflectionClass(Config::class);
        $prop = $ref->getProperty('instance');
        $prop->setAccessible(true);
        $prop->setValue(null, null);
    }

    private function makeAnmeldung(int $id = 1, ?array $data = ['field' => 'value']): Anmeldung
    {
        return new Anmeldung(
            id: $id,
            formular: 'bs',
            formularVersion: null,
            name: 'Max Mustermann',
            email: 'max@example.com',
            status: 'neu',
            data: $data,
            createdAt: new \DateTimeImmutable(),
            updatedAt: null,
            deleted: false,
            deletedAt: null,
        );
    }

    /**
     * Test that SQL injection attempts in formular filter are rejected
     */
    public function testGetExportDataRejectsSqlInjection(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Ungültiger Formularname');

        // Try SQL injection via formular filter
        $this->service->getExportData("bs' OR '1'='1");
    }

    /**
     * Test that semicolons are blocked (SQL command separator)
     */
    public function testGetExportDataRejectsSemicolon(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Ungültiger Formularname');

        $this->service->getExportData('bs;DROP TABLE anmeldungen');
    }

    /**
     * Test that special SQL characters are blocked
     */
    public function testGetExportDataRejectsSpecialCharacters(): void
    {
        $invalidFilters = [
            "bs'--",
            'bs#comment',
            'bs/*comment*/',
            'bs@test',
            'bs test', // space
            'bs"test',
            'bs`test',
        ];

        foreach ($invalidFilters as $filter) {
            try {
                $this->service->getExportData($filter);
                $this->fail("Expected exception for invalid filter: $filter");
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString('Ungültiger Formularname', $e->getMessage());
            }
        }
    }

    /**
     * Test that null filter is accepted (no filter applied)
     */
    public function testGetExportDataAcceptsNullFilter(): void
    {
        // Mock repository to return empty array
        $this->mockRepository
            ->expects($this->once())
            ->method('findForExport')
            ->with(null)
            ->willReturn([]);

        // Should not throw exception
        $result = $this->service->getExportData(null);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('rows', $result);
        $this->assertArrayHasKey('columns', $result);
        $this->assertArrayHasKey('metadata', $result);
        $this->assertEmpty($result['rows']);
    }

    /**
     * Test that empty string filter is accepted (no filter applied)
     */
    public function testGetExportDataAcceptsEmptyStringFilter(): void
    {
        // Mock repository to return empty array
        $this->mockRepository
            ->expects($this->once())
            ->method('findForExport')
            ->with('')
            ->willReturn([]);

        // Should not throw exception
        $result = $this->service->getExportData('');

        $this->assertIsArray($result);
        $this->assertEmpty($result['rows']);
    }

    /**
     * Test that valid formular names are accepted
     */
    public function testGetExportDataAcceptsValidNames(): void
    {
        $validNames = ['bs', 'bk', 'form123', 'test-form', 'test_form'];

        foreach ($validNames as $name) {
            // Mock repository for each call
            $this->mockRepository
                ->expects($this->once())
                ->method('findForExport')
                ->with($name)
                ->willReturn([]);

            // Should not throw exception
            $result = $this->service->getExportData($name);
            $this->assertIsArray($result);

            // Reset mock for next iteration
            $this->setUp();
        }
    }

    /**
     * Test that export data structure is correct
     */
    public function testGetExportDataReturnsCorrectStructure(): void
    {
        // Create mock anmeldung
        $mockAnmeldung = new Anmeldung(
            id: 1,
            formular: 'bs',
            formularVersion: null,
            name: 'Test User',
            email: 'test@example.com',
            status: 'neu',
            data: ['field1' => 'value1', 'field2' => 'value2'],
            createdAt: new \DateTimeImmutable(),
            updatedAt: null,
            deleted: false,
            deletedAt: null
        );

        $this->mockRepository
            ->expects($this->once())
            ->method('findForExport')
            ->with('bs')
            ->willReturn([$mockAnmeldung]);

        $result = $this->service->getExportData('bs');

        // Check structure
        $this->assertArrayHasKey('rows', $result);
        $this->assertArrayHasKey('columns', $result);
        $this->assertArrayHasKey('metadata', $result);

        // Check rows
        $this->assertCount(1, $result['rows']);
        $this->assertSame($mockAnmeldung, $result['rows'][0]);

        // Check columns (should be sorted alphabetically)
        $this->assertContains('field1', $result['columns']);
        $this->assertContains('field2', $result['columns']);
        $this->assertEquals(['field1', 'field2'], $result['columns']);

        // Check metadata
        $this->assertEquals('bs', $result['metadata']['filter']);
        $this->assertEquals(1, $result['metadata']['totalRows']);
        $this->assertInstanceOf(\DateTimeImmutable::class, $result['metadata']['exportDate']);
    }

    /**
     * Test that internal metadata fields are excluded from columns
     */
    public function testGetExportDataExcludesInternalMetadata(): void
    {
        $mockAnmeldung = new Anmeldung(
            id: 1,
            formular: 'bs',
            formularVersion: null,
            name: 'Test User',
            email: 'test@example.com',
            status: 'neu',
            data: [
                'field1' => 'value1',
                '_fieldTypes' => ['field1' => 'text'], // Should be excluded
                '_internal' => 'data', // Should be excluded
            ],
            createdAt: new \DateTimeImmutable(),
            updatedAt: null,
            deleted: false,
            deletedAt: null
        );

        $this->mockRepository
            ->expects($this->once())
            ->method('findForExport')
            ->with(null)
            ->willReturn([$mockAnmeldung]);

        $result = $this->service->getExportData();

        // Only field1 should be in columns, not _fieldTypes or _internal
        $this->assertEquals(['field1'], $result['columns']);
    }

    /**
     * Test that file upload fields are excluded from columns
     */
    public function testGetExportDataExcludesFileUploadFields(): void
    {
        $mockAnmeldung = new Anmeldung(
            id: 1,
            formular: 'bs',
            formularVersion: null,
            name: 'John Doe',
            email: 'john@example.com',
            status: 'neu',
            data: [
                'name' => 'John Doe',
                'upload' => 'data:image/png;base64,iVBORw0KGgoAAAANS...', // Should be excluded
                '_fieldTypes' => ['upload' => 'file'],
            ],
            createdAt: new \DateTimeImmutable(),
            updatedAt: null,
            deleted: false,
            deletedAt: null
        );

        $this->mockRepository
            ->expects($this->once())
            ->method('findForExport')
            ->with(null)
            ->willReturn([$mockAnmeldung]);

        $result = $this->service->getExportData();

        // Only 'name' should be in columns, not 'upload'
        $this->assertEquals(['name'], $result['columns']);
    }

    /**
     * Test formatCellValue with various data types
     */
    public function testFormatCellValueWithNull(): void
    {
        $this->assertEquals('', $this->service->formatCellValue(null));
    }

    public function testFormatCellValueWithBoolean(): void
    {
        $this->assertEquals('Ja', $this->service->formatCellValue(true));
        $this->assertEquals('Nein', $this->service->formatCellValue(false));
    }

    public function testFormatCellValueWithString(): void
    {
        $this->assertEquals('Hello World', $this->service->formatCellValue('Hello World'));
    }

    public function testFormatCellValueWithNumber(): void
    {
        $this->assertEquals('42', $this->service->formatCellValue(42));
        $this->assertEquals('3.14', $this->service->formatCellValue(3.14));
    }

    public function testFormatCellValueWithArray(): void
    {
        $array = ['item1', 'item2', 'item3'];
        $result = $this->service->formatCellValue($array);
        $this->assertEquals('item1, item2, item3', $result);
    }

    public function testFormatCellValueWithDate(): void
    {
        // ISO date should be converted to German format
        $result = $this->service->formatCellValue('2026-01-15');
        $this->assertEquals('15.01.2026', $result);

        // ISO datetime should be converted
        $result = $this->service->formatCellValue('2026-01-15 14:30:00');
        $this->assertEquals('15.01.2026', $result);
    }

    public function testFormatCellValueWithInvalidDate(): void
    {
        // Invalid date format should be returned as-is
        $result = $this->service->formatCellValue('not-a-date');
        $this->assertEquals('not-a-date', $result);
    }

    public function testFormatCellValueWithBase64Data(): void
    {
        // Data URI should be replaced with placeholder
        $base64 = 'data:image/png;base64,' . str_repeat('A', 1000);
        $result = $this->service->formatCellValue($base64);
        $this->assertEquals('[Datei-Upload]', $result);

        // Long base64-like string should be replaced
        $longBase64 = str_repeat('ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789+/=', 20);
        $result = $this->service->formatCellValue($longBase64);
        $this->assertEquals('[Datei-Upload]', $result);
    }

    public function testFormatCellValueWithFileObject(): void
    {
        // Single file object
        $fileObject = ['name' => 'test.pdf', 'content' => 'base64data'];
        $result = $this->service->formatCellValue($fileObject);
        $this->assertEquals('[Datei-Upload]', $result);

        // Array of file objects
        $fileArray = [
            ['name' => 'test1.pdf', 'content' => 'base64data1'],
            ['name' => 'test2.pdf', 'content' => 'base64data2'],
        ];
        $result = $this->service->formatCellValue($fileArray);
        $this->assertEquals('[Datei-Upload]', $result);
    }

    /**
     * Test generateFilename with SQL injection attempts
     */
    public function testGenerateFilenameRejectsSqlInjection(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Ungültiger Formularname');

        $this->service->generateFilename("bs' OR '1'='1");
    }

    public function testGenerateFilenameRejectsSpecialCharacters(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->service->generateFilename('bs;DROP TABLE anmeldungen');
    }

    public function testGenerateFilenameWithNullFilter(): void
    {
        $result = $this->service->generateFilename(null);
        $this->assertMatchesRegularExpression('/^anmeldungen_\d{4}-\d{2}-\d{2}_\d{2}-\d{2}\.xlsx$/', $result);
    }

    public function testGenerateFilenameWithValidFilter(): void
    {
        $result = $this->service->generateFilename('bs');
        $this->assertMatchesRegularExpression('/^anmeldungen_bs_\d{4}-\d{2}-\d{2}_\d{2}-\d{2}\.xlsx$/', $result);
    }

    public function testGenerateFilenameWithId(): void
    {
        $result = $this->service->generateFilename(null, 42);
        $this->assertMatchesRegularExpression('/^anmeldung_42_\d{4}-\d{2}-\d{2}_\d{2}-\d{2}\.xlsx$/', $result);
    }

    /**
     * Test getExportDataById with invalid ID
     */
    public function testGetExportDataByIdThrowsExceptionForInvalidId(): void
    {
        $this->mockRepository
            ->expects($this->once())
            ->method('findById')
            ->with(999)
            ->willReturn(null);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Eintrag nicht gefunden');

        $this->service->getExportDataById(999);
    }

    /**
     * Test getExportDataById with valid ID
     */
    public function testGetExportDataByIdReturnsCorrectStructure(): void
    {
        $mockAnmeldung = new Anmeldung(
            id: 42,
            formular: 'bs',
            formularVersion: null,
            name: 'Test User',
            email: 'test@example.com',
            status: 'neu',
            data: ['field1' => 'value1'],
            createdAt: new \DateTimeImmutable(),
            updatedAt: null,
            deleted: false,
            deletedAt: null
        );

        $this->mockRepository
            ->expects($this->once())
            ->method('findById')
            ->with(42)
            ->willReturn($mockAnmeldung);

        $result = $this->service->getExportDataById(42);

        // Check structure
        $this->assertArrayHasKey('rows', $result);
        $this->assertArrayHasKey('columns', $result);
        $this->assertArrayHasKey('metadata', $result);

        // Check data
        $this->assertCount(1, $result['rows']);
        $this->assertSame($mockAnmeldung, $result['rows'][0]);
        $this->assertEquals(['field1'], $result['columns']);

        // Check metadata
        $this->assertEquals('bs', $result['metadata']['filter']);
        $this->assertEquals(1, $result['metadata']['totalRows']);
        $this->assertTrue($result['metadata']['singleExport']);
    }

    // =========================================================================
    // autoMarkAsRead
    // =========================================================================

    public function testGetExportDataCallsMarkMultipleAsExportedWhenAutoMarkEnabled(): void
    {
        putenv('AUTO_MARK_AS_READ=true');

        $anmeldung = $this->makeAnmeldung(7);
        $this->mockRepository->method('findForExport')->willReturn([$anmeldung]);

        $this->mockStatusService->expects($this->once())
            ->method('markMultipleAsExported')
            ->with([7]);

        $this->service->getExportData();
    }

    public function testGetExportDataSkipsMarkMultipleAsExportedWhenAutoMarkDisabled(): void
    {
        putenv('AUTO_MARK_AS_READ=false');

        $this->mockRepository->method('findForExport')->willReturn([$this->makeAnmeldung(5)]);

        $this->mockStatusService->expects($this->never())
            ->method('markMultipleAsExported');

        $this->service->getExportData();
    }

    public function testGetExportDataPassesAllIdsToMarkMultipleAsExported(): void
    {
        putenv('AUTO_MARK_AS_READ=true');

        $this->mockRepository->method('findForExport')->willReturn([
            $this->makeAnmeldung(1),
            $this->makeAnmeldung(2),
            $this->makeAnmeldung(3),
        ]);

        $this->mockStatusService->expects($this->once())
            ->method('markMultipleAsExported')
            ->with([1, 2, 3]);

        $this->service->getExportData();
    }

    // =========================================================================
    // extractColumns – edge cases
    // =========================================================================

    public function testGetExportDataSkipsAnmeldungWithNullData(): void
    {
        $anmeldungWithNull = $this->makeAnmeldung(1, null);
        $anmeldungWithData = $this->makeAnmeldung(2, ['field_a' => 'value']);

        $this->mockRepository->method('findForExport')
            ->willReturn([$anmeldungWithNull, $anmeldungWithData]);

        $result = $this->service->getExportData();

        $this->assertSame(['field_a'], $result['columns']);
    }

    public function testGetExportDataExcludesFileFieldViaArrayFieldType(): void
    {
        // fieldType value is an array like ['type' => 'file', 'maxSize' => 1024]
        $anmeldung = $this->makeAnmeldung(1, [
            'name'       => 'Max',
            'upload'     => 'data:image/png;base64,abc',
            '_fieldTypes' => ['upload' => ['type' => 'file', 'maxSize' => 1024]],
        ]);

        $this->mockRepository->method('findForExport')->willReturn([$anmeldung]);

        $result = $this->service->getExportData();

        $this->assertSame(['name'], $result['columns']);
        $this->assertNotContains('upload', $result['columns']);
    }

    public function testGetExportDataExcludesLongBase64StringFromColumns(): void
    {
        $longBase64 = str_repeat('ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789+/', 20);
        $anmeldung = $this->makeAnmeldung(1, [
            'name'   => 'Max',
            'binary' => $longBase64,
        ]);

        $this->mockRepository->method('findForExport')->willReturn([$anmeldung]);

        $result = $this->service->getExportData();

        $this->assertSame(['name'], $result['columns']);
    }

    public function testGetExportDataExcludesSingleFileObjectFromColumns(): void
    {
        $anmeldung = $this->makeAnmeldung(1, [
            'name'   => 'Max',
            'upload' => ['name' => 'doc.pdf', 'content' => 'base64data'],
        ]);

        $this->mockRepository->method('findForExport')->willReturn([$anmeldung]);

        $result = $this->service->getExportData();

        $this->assertSame(['name'], $result['columns']);
    }

    public function testGetExportDataExcludesArrayOfFileObjectsFromColumns(): void
    {
        $anmeldung = $this->makeAnmeldung(1, [
            'name'    => 'Max',
            'uploads' => [
                ['name' => 'a.pdf', 'content' => 'data1'],
                ['name' => 'b.pdf', 'content' => 'data2'],
            ],
        ]);

        $this->mockRepository->method('findForExport')->willReturn([$anmeldung]);

        $result = $this->service->getExportData();

        $this->assertSame(['name'], $result['columns']);
    }

    // =========================================================================
    // formatCellValue – flattenArray nested arrays
    // =========================================================================

    public function testFormatCellValueFlattensNestedArrayAsJson(): void
    {
        $nested = [
            'simple'  => 'text',
            'complex' => ['x' => 1, 'y' => 2],
        ];

        $result = $this->service->formatCellValue($nested);

        // simple values as string, nested as JSON
        $this->assertStringContainsString('text', $result);
        $this->assertStringContainsString('{"x":1,"y":2}', $result);
    }

    public function testFormatCellValueFlattensEmptyArray(): void
    {
        $this->assertSame('', $this->service->formatCellValue([]));
    }

    // =========================================================================
    // generateFilename – empty string filter
    // =========================================================================

    public function testGenerateFilenameWithEmptyStringFilter(): void
    {
        $result = $this->service->generateFilename('');

        // Empty string → same as no filter
        $this->assertMatchesRegularExpression('/^anmeldungen_\d{4}-\d{2}-\d{2}_\d{2}-\d{2}\.xlsx$/', $result);
    }

    /**
     * Test that columns are sorted alphabetically
     */
    public function testGetExportDataSortsColumnsAlphabetically(): void
    {
        $mockAnmeldung = new Anmeldung(
            id: 1,
            formular: 'bs',
            formularVersion: null,
            name: 'Test User',
            email: 'test@example.com',
            status: 'neu',
            data: [
                'zebra' => 'value1',
                'apple' => 'value2',
                'banana' => 'value3',
            ],
            createdAt: new \DateTimeImmutable(),
            updatedAt: null,
            deleted: false,
            deletedAt: null
        );

        $this->mockRepository
            ->expects($this->once())
            ->method('findForExport')
            ->willReturn([$mockAnmeldung]);

        $result = $this->service->getExportData();

        // Columns should be sorted alphabetically
        $this->assertEquals(['apple', 'banana', 'zebra'], $result['columns']);
    }
}
