<?php
declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\VirusScanService;
use PHPUnit\Framework\TestCase;

/**
 * Tests for VirusScanService.
 *
 * Because we cannot easily mock fsockopen(), these tests use a
 * subclass that accepts an injected response string — allowing us
 * to test all parsing logic without a real ClamAV daemon.
 */
class VirusScanServiceTest extends TestCase
{
    // =========================================================================
    // parseResponse – via testable subclass
    // =========================================================================

    /** @param string $clamdResponse Raw response as clamd would send it */
    private function makeScannerWith(string $clamdResponse): VirusScanService
    {
        return new class($clamdResponse) extends VirusScanService {
            public function __construct(private string $fakeResponse)
            {
                parent::__construct('localhost', 3310, 1);
            }

            public function scanFile(string $filePath): array
            {
                // Bypass real socket; call the parsing logic via reflection
                $ref = new \ReflectionClass(VirusScanService::class);
                $method = $ref->getMethod('parseResponse');
                $method->setAccessible(true);
                return $method->invoke($this, $this->fakeResponse);
            }
        };
    }

    public function testParsesOkResponse(): void
    {
        $result = $this->makeScannerWith('stream: OK')->scanFile('/fake');
        $this->assertTrue($result['clean']);
        $this->assertNull($result['virus']);
        $this->assertNull($result['error']);
    }

    public function testParsesVirusFoundResponse(): void
    {
        $result = $this->makeScannerWith('stream: Eicar-Signature FOUND')->scanFile('/fake');
        $this->assertFalse($result['clean']);
        $this->assertSame('Eicar-Signature', $result['virus']);
        $this->assertNull($result['error']);
    }

    public function testParsesLongVirusName(): void
    {
        $result = $this->makeScannerWith('stream: Win.Trojan.GenericKD-12345 FOUND')->scanFile('/fake');
        $this->assertFalse($result['clean']);
        $this->assertSame('Win.Trojan.GenericKD-12345', $result['virus']);
    }

    public function testParsesUnexpectedResponse(): void
    {
        $result = $this->makeScannerWith('something unexpected')->scanFile('/fake');
        $this->assertNull($result['clean']);
        $this->assertNull($result['virus']);
        $this->assertNotNull($result['error']);
        $this->assertStringContainsString('Unexpected ClamAV response', $result['error']);
    }

    public function testParsesEmptyResponse(): void
    {
        $result = $this->makeScannerWith('')->scanFile('/fake');
        $this->assertNull($result['clean']);
        $this->assertNotNull($result['error']);
    }

    // =========================================================================
    // fromEnv()
    // =========================================================================

    public function testFromEnvUsesDefaultsWhenEnvMissing(): void
    {
        putenv('CLAMAV_HOST');  // unset
        putenv('CLAMAV_PORT');  // unset

        $scanner = VirusScanService::fromEnv();

        $ref = new \ReflectionClass($scanner);

        $host = $ref->getProperty('host');
        $host->setAccessible(true);
        $this->assertSame('clamav', $host->getValue($scanner));

        $port = $ref->getProperty('port');
        $port->setAccessible(true);
        $this->assertSame(3310, $port->getValue($scanner));
    }

    public function testFromEnvReadsHostAndPort(): void
    {
        // fromEnv() reads $_ENV, not getenv() — set directly
        $prev = [$_ENV['CLAMAV_HOST'] ?? null, $_ENV['CLAMAV_PORT'] ?? null];
        $_ENV['CLAMAV_HOST'] = 'myclamav';
        $_ENV['CLAMAV_PORT'] = '9999';

        try {
            $scanner = VirusScanService::fromEnv();

            $ref = new \ReflectionClass($scanner);

            $host = $ref->getProperty('host');
            $host->setAccessible(true);
            $this->assertSame('myclamav', $host->getValue($scanner));

            $port = $ref->getProperty('port');
            $port->setAccessible(true);
            $this->assertSame(9999, $port->getValue($scanner));
        } finally {
            // Restore original $_ENV state
            if ($prev[0] === null) {
                unset($_ENV['CLAMAV_HOST']);
            } else {
                $_ENV['CLAMAV_HOST'] = $prev[0];
            }
            if ($prev[1] === null) {
                unset($_ENV['CLAMAV_PORT']);
            } else {
                $_ENV['CLAMAV_PORT'] = $prev[1];
            }
        }
    }

    // =========================================================================
    // isAvailable() – when daemon is not running
    // =========================================================================

    public function testIsAvailableReturnsFalseWhenDaemonNotRunning(): void
    {
        // Use a port where nothing listens (unlikely port 1)
        $scanner = new VirusScanService('127.0.0.1', 1, 1);
        $this->assertFalse($scanner->isAvailable());
    }

    // =========================================================================
    // scanFile() – when daemon is not running
    // =========================================================================

    public function testScanFileReturnsErrorWhenDaemonNotRunning(): void
    {
        $scanner = new VirusScanService('127.0.0.1', 1, 1);
        $result = $scanner->scanFile(__FILE__);

        $this->assertNull($result['clean']);
        $this->assertNull($result['virus']);
        $this->assertNotNull($result['error']);
    }

    public function testScanFileReturnsErrorForNonExistentFile(): void
    {
        // Even if ClamAV were running, this file doesn't exist.
        // Since we can't connect, we get connection error first.
        $scanner = new VirusScanService('127.0.0.1', 1, 1);
        $result = $scanner->scanFile('/does/not/exist.pdf');

        $this->assertNull($result['clean']);
        $this->assertNotNull($result['error']);
    }
}
