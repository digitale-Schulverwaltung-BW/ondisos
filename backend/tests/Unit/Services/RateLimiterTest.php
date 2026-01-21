<?php
declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\RateLimiter;
use PHPUnit\Framework\TestCase;

/**
 * Unit Tests for RateLimiter Service
 *
 * Tests rate limiting functionality including:
 * - Request tracking
 * - Limit enforcement
 * - Window expiration
 * - Retry-after calculation
 */
class RateLimiterTest extends TestCase
{
    private string $testStorageDir;
    private RateLimiter $rateLimiter;

    protected function setUp(): void
    {
        parent::setUp();

        // Create temporary storage directory for tests
        $this->testStorageDir = sys_get_temp_dir() . '/ratelimit_test_' . uniqid();
        mkdir($this->testStorageDir, 0755, true);

        // Create rate limiter with low limits for testing
        $this->rateLimiter = new RateLimiter(
            storageDir: $this->testStorageDir,
            maxRequests: 3,
            windowSeconds: 10,
            cleanupProbability: 0 // Disable random cleanup in tests
        );
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        // Clean up test directory
        if (is_dir($this->testStorageDir)) {
            $files = glob($this->testStorageDir . '/*');
            foreach ($files as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
            rmdir($this->testStorageDir);
        }
    }

    public function testAllowsRequestsBelowLimit(): void
    {
        $identifier = '192.168.1.1';

        // First 3 requests should be allowed
        $this->assertTrue($this->rateLimiter->isAllowed($identifier));
        $this->assertTrue($this->rateLimiter->isAllowed($identifier));
        $this->assertTrue($this->rateLimiter->isAllowed($identifier));
    }

    public function testBlocksRequestsAboveLimit(): void
    {
        $identifier = '192.168.1.2';

        // Allow 3 requests
        for ($i = 0; $i < 3; $i++) {
            $this->rateLimiter->isAllowed($identifier);
        }

        // 4th request should be blocked
        $this->assertFalse($this->rateLimiter->isAllowed($identifier));
    }

    public function testGetRemainingRequests(): void
    {
        $identifier = '192.168.1.3';

        // Initially should have 3 requests available
        $this->assertEquals(3, $this->rateLimiter->getRemainingRequests($identifier));

        // After 1 request: 2 remaining
        $this->rateLimiter->isAllowed($identifier);
        $this->assertEquals(2, $this->rateLimiter->getRemainingRequests($identifier));

        // After 2 requests: 1 remaining
        $this->rateLimiter->isAllowed($identifier);
        $this->assertEquals(1, $this->rateLimiter->getRemainingRequests($identifier));

        // After 3 requests: 0 remaining
        $this->rateLimiter->isAllowed($identifier);
        $this->assertEquals(0, $this->rateLimiter->getRemainingRequests($identifier));
    }

    public function testGetRetryAfter(): void
    {
        $identifier = '192.168.1.4';

        // Initially no rate limit, so retry_after should be 0
        $this->assertEquals(0, $this->rateLimiter->getRetryAfter($identifier));

        // Use up all requests
        for ($i = 0; $i < 3; $i++) {
            $this->rateLimiter->isAllowed($identifier);
        }

        // Now rate limited, retry_after should be close to window seconds
        $retryAfter = $this->rateLimiter->getRetryAfter($identifier);
        $this->assertGreaterThan(0, $retryAfter);
        $this->assertLessThanOrEqual(10, $retryAfter);
    }

    public function testDifferentIdentifiersAreIndependent(): void
    {
        $identifier1 = '192.168.1.5';
        $identifier2 = '192.168.1.6';

        // Use up all requests for identifier1
        for ($i = 0; $i < 3; $i++) {
            $this->rateLimiter->isAllowed($identifier1);
        }

        // identifier1 should be rate limited
        $this->assertFalse($this->rateLimiter->isAllowed($identifier1));

        // identifier2 should still be allowed
        $this->assertTrue($this->rateLimiter->isAllowed($identifier2));
    }

    public function testResetClearsLimits(): void
    {
        $identifier = '192.168.1.7';

        // Use up all requests
        for ($i = 0; $i < 3; $i++) {
            $this->rateLimiter->isAllowed($identifier);
        }

        // Should be rate limited
        $this->assertFalse($this->rateLimiter->isAllowed($identifier));

        // Reset the limit
        $this->rateLimiter->reset($identifier);

        // Should be allowed again
        $this->assertTrue($this->rateLimiter->isAllowed($identifier));
    }

    public function testWindowExpiration(): void
    {
        // Use shorter window for this test
        $limiter = new RateLimiter(
            storageDir: $this->testStorageDir,
            maxRequests: 2,
            windowSeconds: 1, // 1 second window
            cleanupProbability: 0
        );

        $identifier = '192.168.1.8';

        // Use up all requests
        $limiter->isAllowed($identifier);
        $limiter->isAllowed($identifier);

        // Should be rate limited
        $this->assertFalse($limiter->isAllowed($identifier));

        // Wait for window to expire
        sleep(2);

        // Should be allowed again
        $this->assertTrue($limiter->isAllowed($identifier));
    }

    public function testStorageDirectoryCreation(): void
    {
        $newDir = sys_get_temp_dir() . '/ratelimit_new_' . uniqid();

        // Directory doesn't exist yet
        $this->assertDirectoryDoesNotExist($newDir);

        // Creating rate limiter should create the directory
        new RateLimiter($newDir);

        $this->assertDirectoryExists($newDir);

        // Cleanup
        rmdir($newDir);
    }

    public function testHandlesCorruptedStorageFiles(): void
    {
        $identifier = '192.168.1.9';

        // Create a corrupted storage file
        $hash = md5($identifier);
        $file = $this->testStorageDir . '/rl_' . $hash . '.json';
        file_put_contents($file, 'invalid json{{{');

        // Should handle gracefully and allow request
        $this->assertTrue($this->rateLimiter->isAllowed($identifier));
    }

    public function testHandlesSpecialCharactersInIdentifier(): void
    {
        $identifier = 'user@example.com:Mozilla/5.0';

        // Should handle special characters via md5 hashing
        $this->assertTrue($this->rateLimiter->isAllowed($identifier));
        $this->assertEquals(2, $this->rateLimiter->getRemainingRequests($identifier));
    }
}
