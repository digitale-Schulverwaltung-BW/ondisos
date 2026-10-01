<?php
declare(strict_types=1);

namespace Tests\Unit\Forms;

use App\Forms\EditorRateLimit;
use App\Services\RateLimiter;
use PHPUnit\Framework\TestCase;

class EditorRateLimitTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/ondisos-ratelimit-' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->dir);
    }

    private function limit(int $max): EditorRateLimit
    {
        return new EditorRateLimit(new RateLimiter($this->dir, $max, 60, 0));
    }

    public function testAllowsUpToTheLimitThenAsksToWait(): void
    {
        $l = $this->limit(3);
        $this->assertNull($l->hit('anna', '10.0.0.1'));
        $this->assertNull($l->hit('anna', '10.0.0.1'));
        $this->assertNull($l->hit('anna', '10.0.0.1'));

        $retry = $l->hit('anna', '10.0.0.1');

        $this->assertNotNull($retry);
        $this->assertGreaterThanOrEqual(1, $retry);
        $this->assertLessThanOrEqual(60, $retry);
    }

    public function testUsersAndAddressesAreCountedSeparately(): void
    {
        $l = $this->limit(1);
        $this->assertNull($l->hit('anna', '10.0.0.1'));
        $this->assertNotNull($l->hit('anna', '10.0.0.1'));

        $this->assertNull($l->hit('ben', '10.0.0.1'), 'another user, same address');
        $this->assertNull($l->hit('anna', '10.0.0.2'), 'same user, another address');
    }

    public function testIdentifiersWithOddCharactersDoNotEscapeTheStorageDirectory(): void
    {
        $l = $this->limit(5);
        $l->hit("../../etc/passwd\0", '::1');
        $l->hit('a b/c', "10.0.0.1\n");

        $this->assertSame([], glob(sys_get_temp_dir() . '/etc*') ?: []);
        $this->assertNotEmpty(glob($this->dir . '/*'));
    }
}
