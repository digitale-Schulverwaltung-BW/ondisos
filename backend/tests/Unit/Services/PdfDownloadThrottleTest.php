<?php
declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\PdfDownloadThrottle;
use PHPUnit\Framework\TestCase;

class PdfDownloadThrottleTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . '/pdf_throttle_test_' . uniqid();
    }

    protected function tearDown(): void
    {
        if (is_dir($this->dir)) {
            $it = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($this->dir, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($it as $file) {
                $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
            }
            rmdir($this->dir);
        }
        unset($_ENV['RATE_LIMIT_ENABLED'], $_ENV['PDF_RATE_LIMIT_ENTRY_MAX']);
        parent::tearDown();
    }

    private function throttle(int $entryMax = 2, int $clientMax = 3, int $tenantMax = 5): PdfDownloadThrottle
    {
        return new PdfDownloadThrottle($this->dir, $entryMax, $clientMax, $tenantMax, 0);
    }

    private function client(string $c): string
    {
        return str_repeat($c, 64);
    }

    public function testOneEntryIsLimitedAfterItsMaximum(): void
    {
        $t = $this->throttle(entryMax: 2);

        $this->assertNull($t->check(1, 1, $this->client('a')));
        $this->assertNull($t->check(1, 1, $this->client('a')));
        $this->assertNotNull($t->check(1, 1, $this->client('a')));
    }

    public function testRefusalReturnsPositiveRetryAfterWithinTheWindow(): void
    {
        $t = $this->throttle(entryMax: 1);
        $t->check(1, 1, null);

        $retryAfter = $t->check(1, 1, null);

        $this->assertGreaterThanOrEqual(1, $retryAfter);
        $this->assertLessThanOrEqual(PdfDownloadThrottle::ENTRY_WINDOW, $retryAfter);
    }

    public function testEntryLimitDoesNotAffectOtherEntries(): void
    {
        $t = $this->throttle(entryMax: 1);
        $t->check(1, 1, null);

        $this->assertNotNull($t->check(1, 1, null));
        $this->assertNull($t->check(2, 1, null));
    }

    public function testOneVisitorIsLimitedAcrossEntries(): void
    {
        $t = $this->throttle(entryMax: 10, clientMax: 3, tenantMax: 100);
        foreach ([1, 2, 3] as $id) {
            $this->assertNull($t->check($id, 1, $this->client('a')));
        }

        $this->assertNotNull($t->check(4, 1, $this->client('a')));
        $this->assertNull($t->check(4, 1, $this->client('b')), 'another visitor is not affected');
    }

    public function testWithoutVisitorIdOnlyEntryAndTenantCount(): void
    {
        // Older frontend: all visitors arrive without id; a shared visitor counter would block the school
        $t = $this->throttle(entryMax: 10, clientMax: 1, tenantMax: 100);

        foreach ([1, 2, 3] as $id) {
            $this->assertNull($t->check($id, 1, null));
        }
    }

    public function testTenantLimitCapsOneSchoolButNotOthers(): void
    {
        $t = $this->throttle(entryMax: 10, clientMax: 10, tenantMax: 2);
        $t->check(1, 1, $this->client('a'));
        $t->check(2, 1, $this->client('b'));

        $this->assertNotNull($t->check(3, 1, $this->client('c')));
        $this->assertNull($t->check(4, 2, $this->client('c')), 'another tenant has its own budget');
    }

    public function testRequestRefusedPerEntryDoesNotUseUpTheTenantBudget(): void
    {
        $t = $this->throttle(entryMax: 1, clientMax: 100, tenantMax: 2);
        $t->check(1, 1, null);
        for ($i = 0; $i < 5; $i++) {
            $this->assertNotNull($t->check(1, 1, null));
        }

        $this->assertNull($t->check(2, 1, null));
    }

    public function testFromEnvIsNullWhenRateLimitingIsDisabled(): void
    {
        $_ENV['RATE_LIMIT_ENABLED'] = 'false';

        $this->assertNull(PdfDownloadThrottle::fromEnv($this->dir));
    }

    public function testFromEnvReadsTheEntryMaximum(): void
    {
        $_ENV['RATE_LIMIT_ENABLED'] = 'true';
        $_ENV['PDF_RATE_LIMIT_ENTRY_MAX'] = '1';
        $t = PdfDownloadThrottle::fromEnv($this->dir);

        $this->assertNotNull($t);
        $this->assertNull($t->check(1, 1, null));
        $this->assertNotNull($t->check(1, 1, null));
    }
}
