<?php
declare(strict_types=1);

namespace Tests\Unit\Frontend;

use PHPUnit\Framework\TestCase;

/**
 * BackendApiClient::fetchFormBundle() with the network replaced (httpGet() overridden).
 */
class BackendApiClientBundleTest extends TestCase
{
    protected function setUp(): void
    {
        require_once __DIR__ . '/../../../../frontend/src/Config/FormConfig.php';
        require_once __DIR__ . '/../../../../frontend/src/Services/BackendApiClient.php';
    }

    /**
     * @param array{status:int, headers?:array<string,string>, body?:string, error?:?string} $response
     */
    private function client(array $response): object
    {
        return new class('http://backend.test/api', 't', 's', $response) extends \Frontend\Services\BackendApiClient {
            public ?string $url = null;
            /** @var list<string> */
            public array $sentHeaders = [];

            /** @param array<string,mixed> $response */
            public function __construct(string $u, string $t, string $s, private array $response)
            {
                parent::__construct($u, $t, $s);
            }

            protected function httpGet(string $url, array $headers): array
            {
                $this->url = $url;
                $this->sentHeaders = $headers;
                return $this->response + ['headers' => [], 'body' => '', 'error' => null];
            }
        };
    }

    private function ok(array $extra = []): string
    {
        return json_encode($extra + ['success' => true, 'config' => ['form' => 'bs.json'], 'survey_json' => '{"pages":[]}', 'theme_json' => '{}']);
    }

    public function testRequestCarriesFormTenantAndWithSurvey(): void
    {
        $c = $this->client(['status' => 200, 'body' => $this->ok()]);
        $c->fetchFormBundle('bs', 'schule a', null);
        $this->assertSame('http://backend.test/api/form-config.php?form=bs&tenant=schule+a&with=survey', $c->url);
    }

    public function testEtagIsSentAsIfNoneMatchOnlyWhenKnown(): void
    {
        $c = $this->client(['status' => 304]);
        $c->fetchFormBundle('bs', 't', 'abc123');
        $this->assertContains('If-None-Match: "abc123"', $c->sentHeaders);

        $c2 = $this->client(['status' => 304]);
        $c2->fetchFormBundle('bs', 't', null);
        $this->assertSame(['Accept: application/json'], $c2->sentHeaders);
    }

    public function testOkParsesConfigSurveyThemeAndEtagHeader(): void
    {
        $c = $this->client(['status' => 200, 'body' => $this->ok(), 'headers' => ['etag' => '"deadbeef"']]);

        $r = $c->fetchFormBundle('bs', 't');

        $this->assertSame('ok', $r['status']);
        $this->assertSame(['form' => 'bs.json'], $r['config']);
        $this->assertSame('{"pages":[]}', $r['survey_json']);
        $this->assertSame('{}', $r['theme_json']);
        $this->assertSame('deadbeef', $r['etag'], 'quotes stripped');
    }

    public function testBackendWithoutBundleSupportAnswersConfigOnly(): void
    {
        $c = $this->client(['status' => 200, 'body' => json_encode(['success' => true, 'config' => ['form' => 'bs.json']])]);

        $r = $c->fetchFormBundle('bs', 't');

        $this->assertSame('ok', $r['status']);
        $this->assertNull($r['survey_json']);
        $this->assertNull($r['theme_json']);
        $this->assertNull($r['etag']);
    }

    public function testEmptySurveyTextCountsAsNone(): void
    {
        $c = $this->client(['status' => 200, 'body' => $this->ok(['survey_json' => '', 'theme_json' => null])]);
        $r = $c->fetchFormBundle('bs', 't');
        $this->assertNull($r['survey_json']);
        $this->assertNull($r['theme_json']);
    }

    /** @return array<string,array{0:array<string,mixed>,1:string}> */
    public static function nonOkResponses(): array
    {
        return [
            '304'            => [['status' => 304], 'not_modified'],
            '404'            => [['status' => 404], 'not_found'],
            '401'            => [['status' => 401], 'denied'],
            '403'            => [['status' => 403], 'denied'],
            '500'            => [['status' => 500, 'body' => 'oops'], 'error'],
            '503'            => [['status' => 503], 'error'],
            'curl error'     => [['status' => 0, 'error' => 'Connection refused'], 'error'],
            'not json'       => [['status' => 200, 'body' => '<html>'], 'error'],
            'success false'  => [['status' => 200, 'body' => '{"success":false}'], 'error'],
            'no config'      => [['status' => 200, 'body' => '{"success":true}'], 'error'],
            'config not obj' => [['status' => 200, 'body' => '{"success":true,"config":"x"}'], 'error'],
        ];
    }

    /**
     * @param array<string,mixed> $response
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('nonOkResponses')]
    public function testStatusMapping(array $response, string $expected): void
    {
        $r = $this->client($response)->fetchFormBundle('bs', 't', 'x');
        $this->assertSame($expected, $r['status']);
        $this->assertArrayNotHasKey('config', $r);
    }
}
