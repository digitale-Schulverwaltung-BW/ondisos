<?php
declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Config\TenantContext;
use App\Services\AuditLogger;
use PHPUnit\Framework\TestCase;

/**
 * Tests for ISOL-03 — tenant_id field in audit log entries.
 *
 * Verifies that every AuditLogger entry contains a tenant_id field, with the
 * correct value depending on TenantContext state:
 * - Integer when context is initialized
 * - null when context is uninitialized (pre-auth events)
 * - null when in all-tenants mode
 *
 * Uses the same real-log-override technique as AuditLoggerTest.php.
 */
class AuditLoggerTenantIdTest extends TestCase
{
    private string $tmpLog;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpLog = sys_get_temp_dir() . '/audit_tenant_test_' . uniqid() . '.log';
        TenantContext::reset();
    }

    protected function tearDown(): void
    {
        foreach ([$this->tmpLog, $this->tmpLog . '.tmp'] as $f) {
            if (file_exists($f)) {
                unlink($f);
            }
        }
        TenantContext::reset();
        parent::tearDown();
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    /**
     * Temporarily swap the real LOG_FILE for our temp file, invoke $callback
     * (which triggers a log write), then restore the original file content.
     *
     * Returns the last JSON-decoded log entry written.
     */
    private function captureLogEntry(callable $callback): array
    {
        $ref      = new \ReflectionClass(AuditLogger::class);
        $realLog  = $ref->getReflectionConstant('LOG_FILE')->getValue();
        $realDir  = dirname($realLog);

        if (!is_dir($realDir)) {
            mkdir($realDir, 0755, true);
        }

        // Save existing real log content
        $realExists  = file_exists($realLog);
        $realContent = $realExists ? file_get_contents($realLog) : null;

        // Start with an empty file at the real path so we can read only the new entry
        file_put_contents($realLog, '');

        try {
            $callback();
            $lines = array_values(array_filter(
                explode("\n", file_get_contents($realLog)),
                fn(string $l) => trim($l) !== ''
            ));
            $this->assertNotEmpty($lines, 'Expected at least one log line to be written');
            return (array) json_decode(end($lines), true);
        } finally {
            // Always restore
            if ($realExists && $realContent !== null) {
                file_put_contents($realLog, $realContent);
            } elseif (file_exists($realLog)) {
                unlink($realLog);
            }
        }
    }

    // =========================================================================
    // Tests
    // =========================================================================

    public function testLogEntryContainsTenantIdWhenContextInitialized(): void
    {
        TenantContext::initialize(5);

        $entry = $this->captureLogEntry(fn() => AuditLogger::loginSuccess('testuser'));

        $this->assertArrayHasKey('tenant_id', $entry);
        $this->assertSame(5, $entry['tenant_id']);
    }

    public function testLogEntryHasNullTenantIdWhenContextUninitialized(): void
    {
        TenantContext::reset(); // ensure uninitialized

        $entry = $this->captureLogEntry(fn() => AuditLogger::loginFailed('testuser'));

        $this->assertArrayHasKey('tenant_id', $entry);
        $this->assertNull($entry['tenant_id']);
    }

    public function testLogEntryHasNullTenantIdInAllTenantsMode(): void
    {
        TenantContext::initAllTenants();

        $entry = $this->captureLogEntry(fn() => AuditLogger::exportRun('all', 42));

        $this->assertArrayHasKey('tenant_id', $entry);
        $this->assertNull($entry['tenant_id']);
    }
}
