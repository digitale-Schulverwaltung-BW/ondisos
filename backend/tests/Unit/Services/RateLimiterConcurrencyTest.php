<?php
declare(strict_types=1);

namespace Tests\Unit\Services;

use PHPUnit\Framework\TestCase;

/**
 * Parallel requests must not get past the limit: separate PHP processes hit one identifier at the same time.
 */
class RateLimiterConcurrencyTest extends TestCase
{
    private const PROCESSES = 24;

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        if (!function_exists('proc_open')) {
            $this->markTestSkipped('proc_open is not available');
        }
        $this->dir = sys_get_temp_dir() . '/ratelimit_concurrency_' . uniqid();
        mkdir($this->dir . '/store', 0755, true);
    }

    protected function tearDown(): void
    {
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $f) {
            $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
        }
        rmdir($this->dir);
        parent::tearDown();
    }

    /**
     * Starts the workers, releases them together and returns their stdout lines.
     *
     * @return list<string>
     */
    private function runWorkers(string $body): array
    {
        $autoload = dirname(__DIR__, 3) . '/vendor/autoload.php';
        $go = $this->dir . '/go';
        $script = $this->dir . '/worker.php';
        file_put_contents($script, '<?php
require ' . var_export($autoload, true) . ';
$store = ' . var_export($this->dir . '/store', true) . ';
while (!file_exists(' . var_export($go, true) . ')) { usleep(200); }
' . $body);

        $procs = [];
        for ($i = 0; $i < self::PROCESSES; $i++) {
            $pipes = [];
            $p = proc_open([PHP_BINARY, $script], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            $this->assertIsResource($p);
            $procs[] = [$p, $pipes];
        }
        usleep(300000); // let all workers reach the barrier
        touch($go);

        $out = [];
        foreach ($procs as [$p, $pipes]) {
            $out[] = trim((string)stream_get_contents($pipes[1]));
            $err = trim((string)stream_get_contents($pipes[2]));
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($p);
            $this->assertSame('', $err, 'worker wrote to stderr');
        }

        return $out;
    }

    public function testParallelRequestsDoNotExceedTheLimit(): void
    {
        $out = $this->runWorkers('
$l = new App\Services\RateLimiter($store, 5, 60, 0);
echo $l->isAllowed("same-visitor") ? "1" : "0";
');

        $this->assertSame(5, count(array_keys($out, '1', true)), 'allowed: ' . implode(',', $out));
        $this->assertCount(self::PROCESSES, $out);
    }

    public function testParallelLoginAttemptsDoNotExceedTheAccountLimit(): void
    {
        $out = $this->runWorkers('
$t = new App\Services\LoginThrottle($store, 5, 100, 900);
echo $t->tryAttempt("10.0.0.1", "admin") ? "1" : "0";
');

        $this->assertSame(5, count(array_keys($out, '1', true)), 'attempts let through: ' . implode(',', $out));
    }
}
