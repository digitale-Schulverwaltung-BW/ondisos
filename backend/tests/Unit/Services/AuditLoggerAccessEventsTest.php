<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Config\TenantContext;
use App\Services\AuditLogger;
use PHPUnit\Framework\TestCase;

/**
 * Audit events for access to personal data and for platform administration (audit finding M3):
 * the entries name IDs, file names and counts, never contents, passwords or secrets.
 *
 * Uses the same real-log-override technique as AuditLoggerTenantIdTest.
 */
class AuditLoggerAccessEventsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        TenantContext::reset();
        TenantContext::initialize(3);
    }

    protected function tearDown(): void
    {
        TenantContext::reset();
        parent::tearDown();
    }

    /** @return array<string, mixed> the entry the callback wrote */
    private function capture(callable $callback): array
    {
        $realLog = (new \ReflectionClass(AuditLogger::class))->getReflectionConstant('LOG_FILE')->getValue();
        if (!is_dir(dirname($realLog))) {
            mkdir(dirname($realLog), 0755, true);
        }
        $before = file_exists($realLog) ? file_get_contents($realLog) : null;
        file_put_contents($realLog, '');

        try {
            $callback();
            $lines = array_values(array_filter(explode("\n", (string)file_get_contents($realLog))));
            $this->assertCount(1, $lines);

            return (array)json_decode($lines[0], true);
        } finally {
            if ($before !== null) {
                file_put_contents($realLog, $before);
            } else {
                @unlink($realLog);
            }
        }
    }

    public function testRecordViewedNamesTheRegistration(): void
    {
        $entry = $this->capture(fn() => AuditLogger::recordViewed(42));

        $this->assertSame('record_viewed', $entry['event']);
        $this->assertSame(['id' => 42], $entry['details']);
        $this->assertSame(3, $entry['tenant_id']);
    }

    public function testFileDownloadedNamesFileAndMode(): void
    {
        $entry = $this->capture(fn() => AuditLogger::fileDownloaded('42_zeugnis.pdf', true));

        $this->assertSame('file_downloaded', $entry['event']);
        $this->assertSame(['file' => '42_zeugnis.pdf', 'mode' => 'view'], $entry['details']);
    }

    public function testPdfDownloadedNamesTheRegistration(): void
    {
        $entry = $this->capture(fn() => AuditLogger::pdfDownloaded(7));

        $this->assertSame('pdf_downloaded', $entry['event']);
        $this->assertSame(['id' => 7], $entry['details']);
    }

    public function testHardDeleteNamesTheRegistration(): void
    {
        $entry = $this->capture(fn() => AuditLogger::recordHardDeleted(9));

        $this->assertSame('hard_delete', $entry['event']);
        $this->assertSame(['id' => 9], $entry['details']);
    }

    public function testFullExportLogsOnlyTheCount(): void
    {
        $entry = $this->capture(fn() => AuditLogger::exportRun('', 120));

        $this->assertSame('export', $entry['event']);
        $this->assertSame(['formular' => 'all', 'count' => 120], $entry['details']);
    }

    public function testSelectedExportAlsoLogsTheIds(): void
    {
        $entry = $this->capture(fn() => AuditLogger::exportRun('bs', 2, [4, 8]));

        $this->assertSame(['formular' => 'bs', 'count' => 2, 'ids' => [4, 8]], $entry['details']);
    }

    public function testExpungeLogsKindIdsAndCount(): void
    {
        $entry = $this->capture(fn() => AuditLogger::expunged('auto', [1, 2, 3]));

        $this->assertSame('expunge', $entry['event']);
        $this->assertSame(['kind' => 'auto', 'ids' => [1, 2, 3], 'count' => 3], $entry['details']);
    }

    public function testPlatformEventNamesTheTargetTenant(): void
    {
        $entry = $this->capture(fn() => AuditLogger::platformEvent('tenant_admin_deactivated', 5, ['admin_id' => 11]));

        $this->assertSame('tenant_admin_deactivated', $entry['event']);
        $this->assertSame(['target_tenant_id' => 5, 'admin_id' => 11], $entry['details']);
    }

    public function testEndpointsCallTheAuditLogger(): void
    {
        // The endpoint scripts are not covered by unit tests: at least make sure the calls stay in place.
        $root = dirname(__DIR__, 3);
        $expected = [
            'public/detail.php' => 'AuditLogger::recordViewed(',
            'public/pdf/admin_download.php' => 'AuditLogger::pdfDownloaded(',
            'public/excel_export.php' => 'AuditLogger::exportRun(',
            'public/hard_delete.php' => 'AuditLogger::recordHardDeleted(',
            'src/Controllers/DownloadController.php' => 'AuditLogger::fileDownloaded(',
        ];
        foreach ($expected as $file => $call) {
            $this->assertStringContainsString($call, (string)file_get_contents($root . '/' . $file), $file);
        }

        $tenants = (string)file_get_contents($root . '/public/tenants.php');
        foreach (['tenant_created', 'tenant_updated', 'api_secret_regenerated', 'tenant_admin_created', 'tenant_admin_deactivated', 'tenant_admin_password_reset'] as $event) {
            $this->assertStringContainsString("'" . $event . "'", $tenants, $event);
        }
    }
}
