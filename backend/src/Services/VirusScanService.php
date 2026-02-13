<?php
// src/Services/VirusScanService.php

declare(strict_types=1);

namespace App\Services;

/**
 * ClamAV virus scanner using the clamd TCP/INSTREAM protocol.
 *
 * Communicates directly with the ClamAV daemon via TCP socket —
 * no PHP extensions or extra binaries required.
 *
 * Setup: Add clamav service to docker-compose.yml (see docker-compose.yml).
 * The clamav/clamav image runs freshclam automatically every 2h for signature updates.
 *
 * Result array:
 *   ['clean' => true,  'virus' => null,              'error' => null]   – file is clean
 *   ['clean' => false, 'virus' => 'Eicar-Signature', 'error' => null]   – virus found
 *   ['clean' => null,  'virus' => null,              'error' => '...']  – scan failed (ClamAV unavailable etc.)
 */
class VirusScanService
{
    private const CHUNK_SIZE = 4096;

    public function __construct(
        private string $host,
        private int $port = 3310,
        private int $timeout = 30
    ) {}

    /**
     * Create from environment variables (CLAMAV_HOST, CLAMAV_PORT).
     */
    public static function fromEnv(): self
    {
        return new self(
            host: $_ENV['CLAMAV_HOST'] ?? 'clamav',
            port: (int)($_ENV['CLAMAV_PORT'] ?? 3310),
        );
    }

    /**
     * Scan a file using clamd's INSTREAM command.
     *
     * @return array{clean: bool|null, virus: string|null, error: string|null}
     */
    public function scanFile(string $filePath): array
    {
        $socket = $this->connect();
        if ($socket === null) {
            return $this->errorResult('ClamAV not reachable at ' . $this->host . ':' . $this->port);
        }

        try {
            // Initiate streaming scan
            fwrite($socket, "nINSTREAM\n");

            // Stream file in chunks with 4-byte big-endian length prefix
            $fp = fopen($filePath, 'rb');
            if ($fp === false) {
                fclose($socket);
                return $this->errorResult('Cannot open file for scanning: ' . $filePath);
            }

            while (!feof($fp)) {
                $chunk = fread($fp, self::CHUNK_SIZE);
                if ($chunk === false) {
                    break;
                }
                fwrite($socket, pack('N', strlen($chunk)) . $chunk);
            }
            fclose($fp);

            // Signal end of stream (zero-length chunk)
            fwrite($socket, pack('N', 0));

            // Read response from clamd
            $response = fgets($socket);
            fclose($socket);

            if ($response === false) {
                return $this->errorResult('No response from ClamAV');
            }

            return $this->parseResponse(trim($response));

        } catch (\Throwable $e) {
            @fclose($socket);
            return $this->errorResult($e->getMessage());
        }
    }

    /**
     * Check if the ClamAV daemon is reachable (PING/PONG).
     */
    public function isAvailable(): bool
    {
        $socket = $this->connect(timeout: 2);
        if ($socket === null) {
            return false;
        }

        fwrite($socket, "nPING\n");
        $response = fgets($socket);
        fclose($socket);

        return trim((string)$response) === 'PONG';
    }

    // =========================================================================
    // Private helpers
    // =========================================================================

    /**
     * @return resource|null
     */
    private function connect(?int $timeout = null)
    {
        $socket = @fsockopen($this->host, $this->port, $errno, $errstr, $timeout ?? $this->timeout);
        if ($socket === false) {
            return null;
        }
        stream_set_timeout($socket, $timeout ?? $this->timeout);
        return $socket;
    }

    /**
     * Parse clamd response string.
     *
     * @return array{clean: bool|null, virus: string|null, error: string|null}
     */
    private function parseResponse(string $response): array
    {
        if (str_ends_with($response, ': OK')) {
            return ['clean' => true, 'virus' => null, 'error' => null];
        }

        // "stream: {VirusName} FOUND"
        if (preg_match('/: (.+) FOUND$/', $response, $matches)) {
            return ['clean' => false, 'virus' => $matches[1], 'error' => null];
        }

        return $this->errorResult('Unexpected ClamAV response: ' . $response);
    }

    /**
     * @return array{clean: null, virus: null, error: string}
     */
    private function errorResult(string $message): array
    {
        return ['clean' => null, 'virus' => null, 'error' => $message];
    }
}
