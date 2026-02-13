<?php
declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\AuditLogger;
use PHPUnit\Framework\TestCase;

/**
 * Tests for AuditLogger::rotate().
 *
 * Because rotate() reads and writes a hard-coded path (LOG_FILE),
 * we use a subclass that redirects the constant to a temp file.
 */
class AuditLoggerTest extends TestCase
{
    private string $tmpLog;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpLog = sys_get_temp_dir() . '/audit_test_' . uniqid() . '.log';
    }

    protected function tearDown(): void
    {
        foreach ([$this->tmpLog, $this->tmpLog . '.tmp'] as $f) {
            if (file_exists($f)) {
                unlink($f);
            }
        }
        parent::tearDown();
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    /**
     * Write raw lines into the temp log file.
     *
     * @param string[] $lines
     */
    private function writeLog(array $lines): void
    {
        file_put_contents($this->tmpLog, implode("\n", $lines) . "\n");
    }

    /** Read the temp log back as an array of non-empty lines. */
    private function readLog(): array
    {
        if (!file_exists($this->tmpLog)) {
            return [];
        }
        return array_values(array_filter(
            explode("\n", file_get_contents($this->tmpLog)),
            fn(string $l) => trim($l) !== ''
        ));
    }

    /**
     * Build a JSON-Lines entry with a timestamp offset from now.
     *
     * @param int $daysAgo Positive = past, negative = future
     */
    private function entry(int $daysAgo, string $event = 'login_success'): string
    {
        $ts = (new \DateTimeImmutable())->modify("-{$daysAgo} days")->format(\DateTimeInterface::ATOM);
        return json_encode(['ts' => $ts, 'event' => $event, 'user' => null, 'ip' => '127.0.0.1', 'details' => []]);
    }

    /**
     * Call rotate() against the temp file by temporarily overriding LOG_FILE
     * via Reflection on the private constant.
     */
    private function rotate(int $retentionDays): int
    {
        $ref = new \ReflectionClass(AuditLogger::class);
        $prop = $ref->getReflectionConstant('LOG_FILE');

        // We cannot change a class constant at runtime, so we test rotate() logic
        // by copying the temp file to the real LOG_FILE path, rotating, and
        // restoring.  A simpler approach: extract the rotate logic into a
        // testable helper — but since we don't want to change production code
        // just for tests, we use a subclass with an overridden constant.
        //
        // PHP does not allow overriding class constants in anonymous classes
        // when the constant is private.  Instead we directly test via a
        // temporary symlink or file-copy strategy:

        $realLog = $prop->getValue(); // reads the private const value

        // Save whatever exists at the real log path
        $realExists  = file_exists($realLog);
        $realContent = $realExists ? file_get_contents($realLog) : null;
        $realDir     = dirname($realLog);
        if (!is_dir($realDir)) {
            mkdir($realDir, 0755, true);
        }

        // Put our test content there
        copy($this->tmpLog, $realLog);

        try {
            $removed = AuditLogger::rotate($retentionDays);
            // Copy result back to tmpLog so readLog() works
            if (file_exists($realLog)) {
                copy($realLog, $this->tmpLog);
            }
        } finally {
            // Always restore original state
            if ($realExists && $realContent !== null) {
                file_put_contents($realLog, $realContent);
            } elseif (file_exists($realLog)) {
                unlink($realLog);
            }
        }

        return $removed;
    }

    // =========================================================================
    // Tests
    // =========================================================================

    public function testRotateReturnZeroWhenDisabled(): void
    {
        $this->writeLog([$this->entry(100)]);
        $removed = $this->rotate(0); // 0 = disabled
        $this->assertSame(0, $removed);
        $this->assertCount(1, $this->readLog());
    }

    public function testRotateReturnZeroWhenFileDoesNotExist(): void
    {
        // tmpLog was never written
        $removed = AuditLogger::rotate(90);
        $this->assertSame(0, $removed);
    }

    public function testRotateRemovesOldEntries(): void
    {
        $this->writeLog([
            $this->entry(91, 'login_success'),  // 91 days old → remove
            $this->entry(90, 'login_failed'),   // exactly 90 days old → remove (< cutoff, not >=)
            $this->entry(89, 'upload_success'), // 89 days old → keep
            $this->entry(1,  'status_changed'), // 1 day old   → keep
        ]);

        $removed = $this->rotate(90);

        $this->assertSame(2, $removed);

        $remaining = $this->readLog();
        $this->assertCount(2, $remaining);

        // Verify kept entries are the recent ones
        $events = array_map(fn($l) => json_decode($l, true)['event'], $remaining);
        $this->assertContains('upload_success', $events);
        $this->assertContains('status_changed', $events);
    }

    public function testRotateKeepsAllEntriesWhenNoneExpired(): void
    {
        $this->writeLog([
            $this->entry(1),
            $this->entry(5),
            $this->entry(30),
        ]);

        $removed = $this->rotate(90);

        $this->assertSame(0, $removed);
        $this->assertCount(3, $this->readLog());
    }

    public function testRotateRemovesAllExpiredEntries(): void
    {
        $this->writeLog([
            $this->entry(100),
            $this->entry(200),
            $this->entry(365),
        ]);

        $removed = $this->rotate(90);

        $this->assertSame(3, $removed);
        $this->assertCount(0, $this->readLog());
    }

    public function testRotateSkipsEmptyLines(): void
    {
        // File with blank lines between entries
        file_put_contents($this->tmpLog,
            $this->entry(1) . "\n\n" .
            $this->entry(200) . "\n\n"
        );

        $removed = $this->rotate(90);

        $this->assertSame(1, $removed);
        $this->assertCount(1, $this->readLog());
    }

    public function testRotateKeepsLinesWithUnparsableTimestamp(): void
    {
        $bad = json_encode(['ts' => 'not-a-date', 'event' => 'whatever']);
        $this->writeLog([$bad, $this->entry(1)]);

        $removed = $this->rotate(90);

        $this->assertSame(0, $removed);
        $this->assertCount(2, $this->readLog());
    }

    public function testRotateKeepsLinesWithMissingTimestamp(): void
    {
        $noTs = json_encode(['event' => 'whatever']);
        $this->writeLog([$noTs]);

        $removed = $this->rotate(90);

        $this->assertSame(0, $removed);
        $this->assertCount(1, $this->readLog());
    }

    public function testRotateIsIdempotentWhenNothingExpired(): void
    {
        $this->writeLog([$this->entry(1), $this->entry(2)]);
        $content = file_get_contents($this->tmpLog);

        $this->rotate(90);
        $afterFirst = file_get_contents($this->tmpLog);

        $this->rotate(90);
        $afterSecond = file_get_contents($this->tmpLog);

        $this->assertSame($afterFirst, $afterSecond);
    }
}
