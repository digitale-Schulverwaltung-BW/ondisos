<?php
declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\SchoolLookupService;
use PHPUnit\Framework\TestCase;

/**
 * Unit Tests for SchoolLookupService
 *
 * Tests fuzzy school name → Dienststellenschlüssel lookup including:
 * - CSV availability detection
 * - Exact and fuzzy name matching
 * - City-based disambiguation
 * - Umlaut/special character normalization
 * - Threshold enforcement
 * - Lazy CSV loading
 */
class SchoolLookupServiceTest extends TestCase
{
    private string $tempCsv;

    /** @var string[] Files to clean up after each test */
    private array $tempFiles = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempCsv = '';
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        foreach ($this->tempFiles as $file) {
            if (file_exists($file)) {
                unlink($file);
            }
        }
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Write a tab-separated CSV to a temp file and return the path.
     *
     * @param array<array<string>> $rows  Rows without quoting; header auto-prepended.
     */
    private function writeTempCsv(array $rows = []): string
    {
        $path = tempnam(sys_get_temp_dir(), 'schulen_test_') . '.csv';
        $this->tempFiles[] = $path;

        $lines = ['"Schul-/Dienststellennummer"' . "\t" . '"Schulbezeichnung (intern)"' . "\t" . '"Anschrift Ort"'];
        foreach ($rows as $row) {
            $lines[] = implode("\t", array_map(fn($v) => '"' . $v . '"', $row));
        }

        file_put_contents($path, implode("\n", $lines));
        return $path;
    }

    // -------------------------------------------------------------------------
    // Availability tests
    // -------------------------------------------------------------------------

    public function testIsAvailableReturnsFalseForMissingFile(): void
    {
        $service = new SchoolLookupService('/tmp/nonexistent_schulen_xyz_' . uniqid() . '.csv');
        $this->assertFalse($service->isAvailable());
    }

    public function testIsAvailableReturnsTrueForExistingFile(): void
    {
        $path = $this->writeTempCsv();
        $service = new SchoolLookupService($path);
        $this->assertTrue($service->isAvailable());
    }

    // -------------------------------------------------------------------------
    // No CSV / empty CSV
    // -------------------------------------------------------------------------

    public function testFindBestMatchReturnsNullWhenCsvMissing(): void
    {
        $service = new SchoolLookupService('/tmp/nonexistent_' . uniqid() . '.csv');
        $this->assertNull($service->findBestMatch('Irgendeine Schule'));
    }

    public function testFindBestMatchReturnsNullForEmptyCsv(): void
    {
        $path = $this->writeTempCsv([]);
        $service = new SchoolLookupService($path);
        $this->assertNull($service->findBestMatch('Abendgymnasium'));
    }

    // -------------------------------------------------------------------------
    // Exact and near-exact matches
    // -------------------------------------------------------------------------

    public function testFindBestMatchExactName(): void
    {
        $path = $this->writeTempCsv([
            ['04303045', 'Abendgymnasium', 'Villingen-Schwenningen'],
            ['04303057', 'Abendgymnasium', 'Reutlingen'],
        ]);
        // Exact name, no city → should match first entry encountered (either city)
        $service = new SchoolLookupService($path);
        $result = $service->findBestMatch('Abendgymnasium');
        $this->assertNotNull($result);
        $this->assertArrayHasKey('schluessel', $result);
        $this->assertArrayHasKey('confidence', $result);
        $this->assertGreaterThanOrEqual(0.9, $result['confidence']);
    }

    public function testFindBestMatchDisambiguatedByCity(): void
    {
        $path = $this->writeTempCsv([
            ['04303045', 'Abendgymnasium', 'Villingen-Schwenningen'],
            ['04303057', 'Abendgymnasium', 'Reutlingen'],
        ]);
        $service = new SchoolLookupService($path);

        $result = $service->findBestMatch('Abendgymnasium, Reutlingen');
        $this->assertNotNull($result);
        $this->assertSame('04303057', $result['schluessel']);

        $result2 = $service->findBestMatch('Abendgymnasium, Villingen-Schwenningen');
        $this->assertNotNull($result2);
        $this->assertSame('04303045', $result2['schluessel']);
    }

    public function testFindBestMatchWithTypo(): void
    {
        $path = $this->writeTempCsv([
            ['04303045', 'Abendgymnasium', 'Villingen-Schwenningen'],
        ]);
        $service = new SchoolLookupService($path);

        // Single character transposition
        $result = $service->findBestMatch('Abendgymnasim');
        $this->assertNotNull($result);
        $this->assertSame('04303045', $result['schluessel']);
    }

    public function testFindBestMatchWithHyphenVariant(): void
    {
        $path = $this->writeTempCsv([
            ['04311674', 'Abendgymnasium am Schul-Dreieck Lörrach', 'Lörrach'],
        ]);
        $service = new SchoolLookupService($path);

        // Hyphen omitted in query — should still match after normalization
        $result = $service->findBestMatch('Abendgymnasium am Schul Dreieck Loerrach, Loerrach');
        $this->assertNotNull($result);
        $this->assertSame('04311674', $result['schluessel']);
    }

    // -------------------------------------------------------------------------
    // Umlaut normalization
    // -------------------------------------------------------------------------

    public function testNormalizeHandlesUmlauts(): void
    {
        $path = $this->writeTempCsv([
            ['04311674', 'Abendgymnasium am Schul-Dreieck Lörrach', 'Lörrach'],
        ]);
        $service = new SchoolLookupService($path);

        // Query uses umlaut, CSV also has umlaut — both normalize to same ASCII
        $result = $service->findBestMatch('Abendgymnasium am Schul-Dreieck Lörrach, Lörrach');
        $this->assertNotNull($result);
        $this->assertSame('04311674', $result['schluessel']);
    }

    // -------------------------------------------------------------------------
    // Threshold enforcement
    // -------------------------------------------------------------------------

    public function testFindBestMatchBelowDefaultThresholdReturnsNull(): void
    {
        $path = $this->writeTempCsv([
            ['04303045', 'Abendgymnasium', 'Villingen-Schwenningen'],
        ]);
        $service = new SchoolLookupService($path);

        // Completely different name — should not match
        $result = $service->findBestMatch('Grundschule Am Waldrand');
        $this->assertNull($result);
    }

    public function testCustomThresholdBlocksLowConfidenceMatch(): void
    {
        $path = $this->writeTempCsv([
            ['04303045', 'Abendgymnasium', 'Villingen-Schwenningen'],
        ]);
        // Threshold at 0.99 — only a near-perfect match should pass
        $service = new SchoolLookupService($path, threshold: 0.99);

        // "Abendgymnasim" (one char off) should be blocked at 0.99 threshold
        $result = $service->findBestMatch('Abendgymnasim');
        $this->assertNull($result);
    }

    public function testCustomThresholdZeroMatchesAnything(): void
    {
        $path = $this->writeTempCsv([
            ['04303045', 'Abendgymnasium', 'Villingen-Schwenningen'],
        ]);
        $service = new SchoolLookupService($path, threshold: 0.0);

        $result = $service->findBestMatch('xyz');
        $this->assertNotNull($result);
    }

    // -------------------------------------------------------------------------
    // Confidence score shape
    // -------------------------------------------------------------------------

    public function testFindBestMatchReturnsConfidenceBetweenZeroAndOne(): void
    {
        $path = $this->writeTempCsv([
            ['04303045', 'Abendgymnasium', 'Villingen-Schwenningen'],
        ]);
        $service = new SchoolLookupService($path, threshold: 0.0);

        $result = $service->findBestMatch('Abendgymnasium');
        $this->assertNotNull($result);
        $this->assertGreaterThanOrEqual(0.0, $result['confidence']);
        $this->assertLessThanOrEqual(1.0, $result['confidence']);
    }

    // -------------------------------------------------------------------------
    // Lazy loading
    // -------------------------------------------------------------------------

    public function testCsvIsLoadedLazily(): void
    {
        $path = $this->writeTempCsv([
            ['04303045', 'Abendgymnasium', 'Villingen-Schwenningen'],
        ]);
        $service = new SchoolLookupService($path);

        // Before any call, $loaded should be false
        $reflection = new \ReflectionProperty(SchoolLookupService::class, 'loaded');
        $reflection->setAccessible(true);
        $this->assertFalse($reflection->getValue($service));

        $service->findBestMatch('Abendgymnasium');

        // After a call, $loaded should be true
        $this->assertTrue($reflection->getValue($service));
    }

    public function testCsvIsOnlyLoadedOnce(): void
    {
        $path = $this->writeTempCsv([
            ['04303045', 'Abendgymnasium', 'Villingen-Schwenningen'],
        ]);
        $service = new SchoolLookupService($path);

        // Call twice
        $service->findBestMatch('Abendgymnasium');
        $service->findBestMatch('Abendgymnasium');

        $reflection = new \ReflectionProperty(SchoolLookupService::class, 'entries');
        $reflection->setAccessible(true);

        // Entries should contain exactly the one row from the CSV (not duplicated)
        $this->assertCount(1, $reflection->getValue($service));
    }

    // -------------------------------------------------------------------------
    // Multiple entries — best match wins
    // -------------------------------------------------------------------------

    public function testFindBestMatchReturnsBestScoringEntry(): void
    {
        $path = $this->writeTempCsv([
            ['00000001', 'Goethe Gymnasium', 'Stuttgart'],
            ['00000002', 'Schiller Gymnasium', 'Stuttgart'],
            ['00000003', 'Mörike Gymnasium', 'Stuttgart'],
        ]);
        $service = new SchoolLookupService($path, threshold: 0.0);

        $result = $service->findBestMatch('Schiller Gymnasium, Stuttgart');
        $this->assertNotNull($result);
        $this->assertSame('00000002', $result['schluessel']);
    }
}
