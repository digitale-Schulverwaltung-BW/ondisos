<?php
declare(strict_types=1);

namespace Tests\Unit\Services;

use PHPUnit\Framework\TestCase;

/**
 * Frontend\Config\FormConfigLoader — fetches a form's config from the backend
 * once and merges it into FormConfig (used by index/save/ical and the WP plugin).
 */
class FormConfigLoaderTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        require_once __DIR__ . '/../../../../frontend/src/Config/FormConfig.php';
        require_once __DIR__ . '/../../../../frontend/src/Services/BackendApiClient.php';
        require_once __DIR__ . '/../../../../frontend/src/Config/FormConfigLoader.php';

        \Frontend\Config\FormConfig::load([]);
        \Frontend\Config\FormConfigLoader::reset();
    }

    /** @param array<string,array<string,mixed>|null> $responses form key => config|null */
    private function fakeClient(array $responses): object
    {
        return new class('http://backend.test/api', 't', 's', $responses) extends \Frontend\Services\BackendApiClient {
            /** @var list<array{string,string}> */
            public array $calls = [];

            /** @param array<string,array<string,mixed>|null> $responses */
            public function __construct(string $u, string $t, string $s, private array $responses)
            {
                parent::__construct($u, $t, $s);
            }

            public function fetchFormConfigResult(string $formKey, string $tenantSlug): array
            {
                $this->calls[] = [$formKey, $tenantSlug];
                $config = $this->responses[$formKey] ?? null;

                return $config !== null
                    ? ['config' => $config, 'reason' => null, 'http_code' => 200, 'detail' => '']
                    : ['config' => null, 'reason' => $this->failReason, 'http_code' => 0, 'detail' => 'canned failure'];
            }

            public string $failReason = 'not_found';
        };
    }

    public function testLoadsConfigFromBackendAndMakesFormAvailable(): void
    {
        $client = $this->fakeClient(['bs' => ['form' => 'bs.json', 'theme' => 't.json']]);

        $this->assertTrue(\Frontend\Config\FormConfigLoader::ensure('bs', $client, 'schule-a'));
        $this->assertTrue(\Frontend\Config\FormConfig::exists('bs'));
        $this->assertSame('bs.json', \Frontend\Config\FormConfig::get('bs')['form']);
        $this->assertSame([['bs', 'schule-a']], $client->calls);
    }

    public function testDoesNotRefetchAnAlreadyLoadedForm(): void
    {
        $client = $this->fakeClient(['bs' => ['form' => 'bs.json']]);

        \Frontend\Config\FormConfigLoader::ensure('bs', $client);
        \Frontend\Config\FormConfigLoader::ensure('bs', $client);

        $this->assertCount(1, $client->calls);
    }

    public function testKeepsPreviouslyLoadedFormsWhenLoadingAnotherOne(): void
    {
        $client = $this->fakeClient(['bs' => ['form' => 'bs.json'], 'vabo' => ['form' => 'vabo.json']]);

        \Frontend\Config\FormConfigLoader::ensure('bs', $client);
        \Frontend\Config\FormConfigLoader::ensure('vabo', $client);

        $this->assertSame(['bs', 'vabo'], \Frontend\Config\FormConfig::getAllFormKeys());
    }

    public function testUnknownFormOrUnreachableBackendReturnsFalseAndAsksOnlyOnce(): void
    {
        $client = $this->fakeClient([]);

        $this->assertFalse(\Frontend\Config\FormConfigLoader::ensure('nope', $client));
        $this->assertFalse(\Frontend\Config\FormConfigLoader::ensure('nope', $client));
        $this->assertFalse(\Frontend\Config\FormConfig::exists('nope'));
        $this->assertCount(1, $client->calls);
    }

    public function testEmptyFormKeyIsRejectedWithoutBackendCall(): void
    {
        $client = $this->fakeClient(['' => ['form' => 'x']]);

        $this->assertFalse(\Frontend\Config\FormConfigLoader::ensure('', $client));
        $this->assertSame([], $client->calls);
    }

    public function testTenantSlugComesFromEnvironmentWithDefaultFallback(): void
    {
        putenv('TENANT_SLUG');
        $this->assertSame('default', \Frontend\Config\FormConfigLoader::tenantSlug());

        putenv('TENANT_SLUG=bsz-karlsruhe');
        try {
            $this->assertSame('bsz-karlsruhe', \Frontend\Config\FormConfigLoader::tenantSlug());
        } finally {
            putenv('TENANT_SLUG');
        }
    }

    public function testFailureReasonIsRecordedPerForm(): void
    {
        $client = $this->fakeClient([]);
        $client->failReason = 'unreachable';

        $this->assertFalse(\Frontend\Config\FormConfigLoader::ensure('bs', $client));

        $failure = \Frontend\Config\FormConfigLoader::failure('bs');
        $this->assertSame('unreachable', $failure['reason']);
        $this->assertSame('canned failure', $failure['detail']);
        $this->assertSame('http://backend.test/api', $failure['backend_url']);
        $this->assertNull(\Frontend\Config\FormConfigLoader::failure('other'));
    }

    public function testSuccessLeavesNoFailureAndResetClearsIt(): void
    {
        $client = $this->fakeClient(['bs' => ['form' => 'bs.json']]);
        \Frontend\Config\FormConfigLoader::ensure('bs', $client);
        $this->assertNull(\Frontend\Config\FormConfigLoader::failure('bs'));

        $client->failReason = 'not_found';
        \Frontend\Config\FormConfigLoader::ensure('nope', $client);
        $this->assertNotNull(\Frontend\Config\FormConfigLoader::failure('nope'));

        \Frontend\Config\FormConfigLoader::reset();
        $this->assertNull(\Frontend\Config\FormConfigLoader::failure('nope'));
    }

    public function testBackendUrlIsRedactedBeforeItIsStoredOrLogged(): void
    {
        $this->assertSame('http://***@host/api', \Frontend\Config\FormConfigLoader::redactUrl('http://user:pa55@host/api'));
        $this->assertSame('https://***@host:9080/api', \Frontend\Config\FormConfigLoader::redactUrl('https://token@host:9080/api'));
        $this->assertSame('http://backend/api', \Frontend\Config\FormConfigLoader::redactUrl('http://backend/api'));
    }
}
