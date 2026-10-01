<?php
declare(strict_types=1);

namespace Tests\Unit\Frontend;

use PHPUnit\Framework\TestCase;

class FormBundleCacheTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        require_once __DIR__ . '/../../../../frontend/src/Config/FormBundleCache.php';
        $this->dir = sys_get_temp_dir() . '/ondisos-cache-test-' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->dir);
    }

    private function cache(): \Frontend\Config\FormBundleCache
    {
        return new \Frontend\Config\FormBundleCache($this->dir);
    }

    public function testRoundTrip(): void
    {
        $this->assertTrue($this->cache()->put('schule-a', 'bs', 'etag1', ['form' => 'bs.json', 'umlaut' => 'Größe'], '{"pages":[]}', '{"t":1}'));

        $got = $this->cache()->get('schule-a', 'bs');
        $this->assertSame('etag1', $got['etag']);
        $this->assertSame(['form' => 'bs.json', 'umlaut' => 'Größe'], $got['config']);
        $this->assertSame('{"pages":[]}', $got['survey_json']);
        $this->assertSame('{"t":1}', $got['theme_json']);
        $this->assertEqualsWithDelta(time(), $got['stored_at'], 5);
    }

    public function testNullSurveyThemeAndEtagAreKept(): void
    {
        $this->cache()->put('t', 'f', null, ['a' => 1], null, null);
        $got = $this->cache()->get('t', 'f');
        $this->assertNull($got['etag']);
        $this->assertNull($got['survey_json']);
        $this->assertNull($got['theme_json']);
    }

    public function testMissEntriesAreNull(): void
    {
        $this->assertNull($this->cache()->get('t', 'nope'));
    }

    public function testEntriesAreSeparatedByTenantAndForm(): void
    {
        $c = $this->cache();
        $c->put('a', 'bs', 'ea', ['who' => 'a'], null, null);
        $c->put('b', 'bs', 'eb', ['who' => 'b'], null, null);
        $c->put('a', 'zq', 'ez', ['who' => 'az'], null, null);

        $this->assertSame('a', $c->get('a', 'bs')['config']['who']);
        $this->assertSame('b', $c->get('b', 'bs')['config']['who']);
        $this->assertSame('az', $c->get('a', 'zq')['config']['who']);
    }

    public function testFileIsGuardedAndNamedByHashOnly(): void
    {
        $this->cache()->put('schule-a', '../evil', 'e', ['notify_email' => 'x@y.de'], null, null);

        $files = glob($this->dir . '/*') ?: [];
        $this->assertCount(1, $files);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}\.php$/', basename($files[0]), 'no user input in the file name');
        $this->assertStringStartsWith('<?php', (string)file_get_contents($files[0]), 'content is never served as plain text');
        $this->assertStringNotContainsString('..', basename($files[0]));
    }

    public function testGuardedFileExecutesToNothing(): void
    {
        $this->cache()->put('t', 'f', 'e', ['notify_email' => 'geheim@example.de'], '{"s":1}', null);
        $file = (glob($this->dir . '/*') ?: [])[0];

        // What a web server would do if the file were requested: run it as PHP.
        $output = shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($file) . ' 2>&1');

        $this->assertSame('', trim((string)$output), 'the guard line ends the script before any data is printed');
    }

    public function testCorruptOrForeignFilesAreIgnored(): void
    {
        $c = $this->cache();
        $c->put('t', 'f', 'e', ['a' => 1], null, null);
        $file = (glob($this->dir . '/*') ?: [])[0];

        file_put_contents($file, 'garbage');
        $this->assertNull($c->get('t', 'f'), 'no guard line');

        file_put_contents($file, "<?php http_response_code(404); exit; ?>\n{not json");
        $this->assertNull($c->get('t', 'f'), 'broken json');

        file_put_contents($file, "<?php http_response_code(404); exit; ?>\n{\"etag\":\"x\"}");
        $this->assertNull($c->get('t', 'f'), 'no config');
    }

    public function testForgetRemovesTheEntry(): void
    {
        $c = $this->cache();
        $c->put('t', 'f', 'e', ['a' => 1], null, null);
        $c->forget('t', 'f');
        $this->assertNull($c->get('t', 'f'));
        $c->forget('t', 'f'); // second time: no error
        $this->addToAssertionCount(1);
    }

    public function testUnwritableLocationDisablesTheCacheWithoutErrors(): void
    {
        $blocked = new \Frontend\Config\FormBundleCache('/proc/definitely/not/writable');
        $this->assertFalse($blocked->put('t', 'f', 'e', ['a' => 1], null, null));
        $this->assertNull($blocked->get('t', 'f'));
    }

    public function testNoTemporaryFilesAreLeftBehind(): void
    {
        $c = $this->cache();
        for ($i = 0; $i < 5; $i++) {
            $c->put('t', 'f', "e{$i}", ['i' => $i], null, null);
        }
        $this->assertCount(1, glob($this->dir . '/*') ?: []);
        $this->assertSame(4, $c->get('t', 'f')['config']['i']);
    }
}
