<?php
declare(strict_types=1);

namespace Tests\Unit\Frontend;

use Frontend\Config\FormConfig;
use Frontend\Services\AnmeldungService;
use Frontend\Services\BackendApiClient;
use PHPUnit\Framework\TestCase;

/**
 * Prefill links carry the data in the fragment (#prefill=), which browsers never send to a server:
 * it stays out of access logs, proxies and analytics.
 */
class PrefillLinkTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $src = __DIR__ . '/../../../../frontend/src/';
        foreach (['Config/FormConfig', 'Services/BackendApiClient', 'Services/MessageService', 'Utils/ClientIp',
                  'Utils/ConsentCheck', 'Services/AnmeldungService'] as $file) {
            require_once $src . $file . '.php';
        }
        FormConfig::load(['bs' => ['form' => 'bs.json', 'prefill_fields' => ['Ausbildungsbetrieb', 'Ausbilder']]]);
    }

    protected function tearDown(): void
    {
        unset($_SERVER['REQUEST_SCHEME'], $_SERVER['HTTP_HOST'], $_SERVER['SCRIPT_NAME']);
        parent::tearDown();
    }

    private function service(): AnmeldungService
    {
        return new AnmeldungService(new BackendApiClient('http://backend.test/api', 'default', 's'));
    }

    private function link(?string $pageUrl = 'https://schule.example/anmeldung/?utm_source=x'): string
    {
        $link = $this->service()->generatePrefillLink(
            'bs',
            ['Ausbildungsbetrieb' => 'Müller GmbH', 'Ausbilder' => 'Jörg ?+/', 'Name' => 'Azubi, Anna'],
            $pageUrl
        );
        $this->assertNotNull($link);

        return $link;
    }

    /** @return array<string,mixed> */
    private function decodeFragment(string $link): array
    {
        $fragment = (string)parse_url($link, PHP_URL_FRAGMENT);
        parse_str($fragment, $params);
        $b64 = strtr((string)$params['prefill'], '-_', '+/');

        return json_decode((string)base64_decode($b64), true, 512, JSON_THROW_ON_ERROR);
    }

    public function testDataIsInTheFragmentNotInTheQuery(): void
    {
        $link = $this->link();

        $this->assertStringNotContainsString('prefill', (string)parse_url($link, PHP_URL_QUERY));
        $this->assertStringStartsWith('prefill=', (string)parse_url($link, PHP_URL_FRAGMENT));
        $this->assertSame('https://schule.example/anmeldung/?form=bs', strstr($link, '#', true));
    }

    public function testOnlyConfiguredFieldsAreIncluded(): void
    {
        $this->assertSame(
            ['Ausbildungsbetrieb' => 'Müller GmbH', 'Ausbilder' => 'Jörg ?+/'],
            $this->decodeFragment($this->link())
        );
    }

    public function testEncodingIsBase64UrlWithoutPadding(): void
    {
        $value = (string)parse_url($this->link(), PHP_URL_FRAGMENT);

        $this->assertMatchesRegularExpression('/^prefill=[A-Za-z0-9_-]+$/', $value);
    }

    public function testStandaloneLinkPointsToIndexPhpOfTheSameDirectory(): void
    {
        $_SERVER['REQUEST_SCHEME'] = 'https';
        $_SERVER['HTTP_HOST'] = 'anmeldung.example';
        $_SERVER['SCRIPT_NAME'] = '/save.php';

        $this->assertStringStartsWith('https://anmeldung.example/index.php?form=bs#prefill=', $this->link(null));
    }

    public function testNoLinkWithoutPrefillFields(): void
    {
        FormConfig::load(['bs' => ['form' => 'bs.json']]);

        $this->assertNull($this->service()->generatePrefillLink('bs', ['Ausbildungsbetrieb' => 'x']));
    }
}
