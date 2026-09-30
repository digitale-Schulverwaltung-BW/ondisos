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

            public function fetchFormConfig(string $formKey, string $tenantSlug): ?array
            {
                $this->calls[] = [$formKey, $tenantSlug];
                return $this->responses[$formKey] ?? null;
            }
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
}
