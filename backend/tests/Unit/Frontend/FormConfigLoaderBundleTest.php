<?php
declare(strict_types=1);

namespace Tests\Unit\Frontend;

use PHPUnit\Framework\TestCase;

/**
 * FormConfigLoader::ensureWithSurvey(): backend, ETag revalidation, cache and stale-if-error.
 */
class FormConfigLoaderBundleTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $base = __DIR__ . '/../../../../frontend/src/';
        require_once $base . 'Config/FormConfig.php';
        require_once $base . 'Services/BackendApiClient.php';
        require_once $base . 'Config/FormBundleCache.php';
        require_once $base . 'Config/FormConfigLoader.php';

        \Frontend\Config\FormConfig::load([]);
        \Frontend\Config\FormConfigLoader::reset();
        $this->dir = sys_get_temp_dir() . '/ondisos-loader-' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->dir);
        \Frontend\Config\FormConfigLoader::reset();
    }

    private function cache(): \Frontend\Config\FormBundleCache
    {
        return new \Frontend\Config\FormBundleCache($this->dir);
    }

    /** @param list<array<string,mixed>> $answers answers in call order (last one repeats) */
    private function client(array $answers): object
    {
        return new class('http://x', 't', 's', $answers) extends \Frontend\Services\BackendApiClient {
            /** @var list<array{string,string,?string}> */
            public array $calls = [];

            /** @param list<array<string,mixed>> $answers */
            public function __construct(string $u, string $t, string $s, private array $answers)
            {
                parent::__construct($u, $t, $s);
            }

            public function fetchFormBundle(string $formKey, string $tenantSlug, ?string $etag = null): array
            {
                $this->calls[] = [$formKey, $tenantSlug, $etag];
                $i = min(count($this->calls), count($this->answers)) - 1;
                return $this->answers[$i];
            }
        };
    }

    /** @return array<string,mixed> */
    private function ok(string $etag = 'e1', string $survey = '{"v":1}'): array
    {
        return ['status' => 'ok', 'config' => ['form' => 'bs.json', 'theme' => 't.json', 'version' => $etag], 'survey_json' => $survey, 'theme_json' => '{"th":1}', 'etag' => $etag];
    }

    private function newRequest(): void
    {
        \Frontend\Config\FormConfig::load([]);
        \Frontend\Config\FormConfigLoader::reset();
    }

    public function testFirstLoadFetchesAndCaches(): void
    {
        $client = $this->client([$this->ok('e1')]);

        $this->assertTrue(\Frontend\Config\FormConfigLoader::ensureWithSurvey('bs', $client, 'schule-a', $this->cache()));

        $this->assertSame([['bs', 'schule-a', null]], $client->calls, 'nothing cached yet: no ETag sent');
        $this->assertSame('{"v":1}', \Frontend\Config\FormConfigLoader::surveyJson('bs'));
        $this->assertSame('{"th":1}', \Frontend\Config\FormConfigLoader::themeJson('bs'));
        $this->assertSame('bs.json', \Frontend\Config\FormConfig::get('bs')['form']);
        $this->assertSame('e1', $this->cache()->get('schule-a', 'bs')['etag']);
    }

    public function testSecondRequestRevalidatesWithEtagAndServesTheCacheOn304(): void
    {
        \Frontend\Config\FormConfigLoader::ensureWithSurvey('bs', $this->client([$this->ok('e1')]), 't', $this->cache());
        $this->newRequest();

        $client = $this->client([['status' => 'not_modified']]);
        $this->assertTrue(\Frontend\Config\FormConfigLoader::ensureWithSurvey('bs', $client, 't', $this->cache()));

        $this->assertSame([['bs', 't', 'e1']], $client->calls);
        $this->assertSame('{"v":1}', \Frontend\Config\FormConfigLoader::surveyJson('bs'));
        $this->assertSame('bs.json', \Frontend\Config\FormConfig::get('bs')['form']);
    }

    public function testChangedFormReplacesTheCache(): void
    {
        \Frontend\Config\FormConfigLoader::ensureWithSurvey('bs', $this->client([$this->ok('e1', '{"v":1}')]), 't', $this->cache());
        $this->newRequest();

        \Frontend\Config\FormConfigLoader::ensureWithSurvey('bs', $this->client([$this->ok('e2', '{"v":2}')]), 't', $this->cache());

        $this->assertSame('{"v":2}', \Frontend\Config\FormConfigLoader::surveyJson('bs'));
        $this->assertSame('e2', $this->cache()->get('t', 'bs')['etag']);
    }

    public function testBackendDownServesTheCachedCopy(): void
    {
        \Frontend\Config\FormConfigLoader::ensureWithSurvey('bs', $this->client([$this->ok('e1')]), 't', $this->cache());
        $this->newRequest();

        $client = $this->client([['status' => 'error']]);
        $this->assertTrue(\Frontend\Config\FormConfigLoader::ensureWithSurvey('bs', $client, 't', $this->cache()));
        $this->assertSame('{"v":1}', \Frontend\Config\FormConfigLoader::surveyJson('bs'));
    }

    public function testBackendDownWithoutCacheFails(): void
    {
        $this->assertFalse(\Frontend\Config\FormConfigLoader::ensureWithSurvey('bs', $this->client([['status' => 'error']]), 't', $this->cache()));
        $this->assertFalse(\Frontend\Config\FormConfig::exists('bs'));
    }

    public function testCacheOlderThanMaxStaleIsNotServedWhenBackendIsDown(): void
    {
        $cache = $this->cache();
        $cache->put('t', 'bs', 'e1', ['form' => 'bs.json'], '{"v":1}', null);
        $file = (glob($this->dir . '/*') ?: [])[0];
        $raw  = (string)file_get_contents($file);
        $old  = time() - \Frontend\Config\FormConfigLoader::MAX_STALE_SECONDS - 10;
        file_put_contents($file, preg_replace('/"stored_at":\d+/', '"stored_at":' . $old, $raw));

        $this->assertFalse(\Frontend\Config\FormConfigLoader::ensureWithSurvey('bs', $this->client([['status' => 'error']]), 't', $cache));
    }

    public function testRecentlyStoredCacheIsServedWhenBackendIsDown(): void
    {
        $cache = $this->cache();
        $cache->put('t', 'bs', 'e1', ['form' => 'bs.json'], '{"v":1}', null);
        $this->assertTrue(\Frontend\Config\FormConfigLoader::ensureWithSurvey('bs', $this->client([['status' => 'error']]), 't', $cache));
    }

    /** @return array<string,array{0:string}> */
    public static function goneStatuses(): array
    {
        return ['not found (form deleted)' => ['not_found'], 'denied (tenant deactivated)' => ['denied']];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('goneStatuses')]
    public function testDeletedFormOrDeactivatedTenantIsNotServedFromTheCache(string $status): void
    {
        \Frontend\Config\FormConfigLoader::ensureWithSurvey('bs', $this->client([$this->ok('e1')]), 't', $this->cache());
        $this->newRequest();

        $this->assertFalse(\Frontend\Config\FormConfigLoader::ensureWithSurvey('bs', $this->client([['status' => $status]]), 't', $this->cache()));
        $this->assertNull($this->cache()->get('t', 'bs'), 'cache entry removed');

        $this->newRequest();
        $this->assertFalse(\Frontend\Config\FormConfigLoader::ensureWithSurvey('bs', $this->client([['status' => 'error']]), 't', $this->cache()), 'and not resurrected when the backend goes down later');
    }

    public function testBackendWithoutSurveyDeliversNullSoTheFileIsUsed(): void
    {
        $answer = ['status' => 'ok', 'config' => ['form' => 'bs.json'], 'survey_json' => null, 'theme_json' => null, 'etag' => null];
        $this->assertTrue(\Frontend\Config\FormConfigLoader::ensureWithSurvey('bs', $this->client([$answer]), 't', $this->cache()));
        $this->assertNull(\Frontend\Config\FormConfigLoader::surveyJson('bs'));
        $this->assertNull(\Frontend\Config\FormConfigLoader::themeJson('bs'));
    }

    public function testNotModifiedWithoutACachedCopyFails(): void
    {
        // Should not happen (no ETag was sent), but must not produce an empty form.
        $this->assertFalse(\Frontend\Config\FormConfigLoader::ensureWithSurvey('bs', $this->client([['status' => 'not_modified']]), 't', $this->cache()));
    }

    public function testCachesOfDifferentTenantsDoNotMix(): void
    {
        \Frontend\Config\FormConfigLoader::ensureWithSurvey('bs', $this->client([$this->ok('ea', '{"who":"a"}')]), 'a', $this->cache());
        $this->newRequest();

        $client = $this->client([$this->ok('eb', '{"who":"b"}')]);
        \Frontend\Config\FormConfigLoader::ensureWithSurvey('bs', $client, 'b', $this->cache());

        $this->assertSame([['bs', 'b', null]], $client->calls, "tenant b must not send tenant a's ETag");
        $this->assertSame('{"who":"b"}', \Frontend\Config\FormConfigLoader::surveyJson('bs'));
    }

    public function testBackendIsAskedOnlyOncePerRequestEvenWhenItFails(): void
    {
        $client = $this->client([['status' => 'error']]);
        \Frontend\Config\FormConfigLoader::ensureWithSurvey('bs', $client, 't', $this->cache());
        \Frontend\Config\FormConfigLoader::ensureWithSurvey('bs', $client, 't', $this->cache());
        $this->assertCount(1, $client->calls);

        $client2 = $this->client([$this->ok()]);
        $this->newRequest();
        \Frontend\Config\FormConfigLoader::ensureWithSurvey('zq', $client2, 't', $this->cache());
        \Frontend\Config\FormConfigLoader::ensureWithSurvey('zq', $client2, 't', $this->cache());
        $this->assertCount(1, $client2->calls);
    }

    public function testEmptyFormKeyIsRejectedWithoutBackendCall(): void
    {
        $client = $this->client([$this->ok()]);
        $this->assertFalse(\Frontend\Config\FormConfigLoader::ensureWithSurvey('', $client, 't', $this->cache()));
        $this->assertSame([], $client->calls);
    }

    public function testUnwritableCacheDoesNotBreakTheForm(): void
    {
        $blocked = new \Frontend\Config\FormBundleCache('/proc/not/writable');
        $this->assertTrue(\Frontend\Config\FormConfigLoader::ensureWithSurvey('bs', $this->client([$this->ok()]), 't', $blocked));
        $this->assertSame('{"v":1}', \Frontend\Config\FormConfigLoader::surveyJson('bs'));
    }
}
