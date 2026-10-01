<?php
declare(strict_types=1);

namespace Tests\Unit\Services;

use PHPUnit\Framework\TestCase;

/**
 * BackendApiClient::fetchFormConfigResult() / checkTenant() / healthCheck():
 * the client must say WHY a call failed (unreachable backend vs. unknown form vs. rejected tenant),
 * because each needs a different fix.
 */
class BackendApiClientResultTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__ . '/../../../../frontend/src/Config/FormConfig.php';
        require_once __DIR__ . '/../../../../frontend/src/Services/BackendApiClient.php';
    }

    /**
     * @param array{body?: string, code?: int, error?: string} $response canned network answer
     */
    private function client(array $response): object
    {
        $response += ['body' => '', 'code' => 0, 'error' => ''];

        return new class('http://backend.test/api', 'default', 's', $response) extends \Frontend\Services\BackendApiClient {
            /** @var list<string> */
            public array $urls = [];

            /** @param array{body: string, code: int, error: string} $canned */
            public function __construct(string $u, string $t, string $s, private array $canned)
            {
                parent::__construct($u, $t, $s);
            }

            protected function httpGet(string $url, int $timeoutSeconds): array
            {
                $this->urls[] = $url;
                return $this->canned;
            }
        };
    }

    public function testSuccessReturnsConfigAndNoReason(): void
    {
        $c = $this->client(['code' => 200, 'body' => '{"success":true,"config":{"form":"bs.json"}}']);

        $r = $c->fetchFormConfigResult('bs', 'default');

        $this->assertSame(['form' => 'bs.json'], $r['config']);
        $this->assertNull($r['reason']);
        $this->assertSame(['form' => 'bs.json'], $c->fetchFormConfig('bs', 'default'), 'fetchFormConfig() delegates');
        $this->assertSame('http://backend.test/api/form-config.php?form=bs&tenant=default', $c->urls[0]);
    }

    public function testUnreachableBackendIsDistinguished(): void
    {
        $c = $this->client(['code' => 0, 'error' => 'Could not resolve host: ocalhost']);

        $r = $c->fetchFormConfigResult('bs', 'default');

        $this->assertNull($r['config']);
        $this->assertSame('unreachable', $r['reason']);
        $this->assertStringContainsString('Could not resolve host', $r['detail']);
        $this->assertNull($c->fetchFormConfig('bs', 'default'));
    }

    public function testNoResponseWithoutErrorTextCountsAsUnreachable(): void
    {
        $r = $this->client(['code' => 0, 'error' => ''])->fetchFormConfigResult('bs', 'default');

        $this->assertSame('unreachable', $r['reason']);
        $this->assertSame('no response', $r['detail']);
    }

    public function testUnknownFormIs404NotFound(): void
    {
        $r = $this->client(['code' => 404, 'body' => '{"error":"Form not found"}'])->fetchFormConfigResult('nope', 'default');

        $this->assertSame('not_found', $r['reason']);
        $this->assertStringContainsString('"nope"', $r['detail']);
        $this->assertStringContainsString('"default"', $r['detail']);
    }

    public function testRejectedTenantIs401Unauthorized(): void
    {
        $r = $this->client(['code' => 401])->fetchFormConfigResult('bs', 'wrong-slug');

        $this->assertSame('unauthorized', $r['reason']);
        $this->assertStringContainsString('wrong-slug', $r['detail']);
    }

    /**
     * @return array<string,array{int,string}>
     */
    public static function brokenAnswers(): array
    {
        return [
            'server error'     => [500, ''],
            'html instead'     => [200, '<html>login</html>'],
            'success false'    => [200, '{"success":false}'],
            'config missing'   => [200, '{"success":true}'],
            'config not array' => [200, '{"success":true,"config":"x"}'],
        ];
    }

    /**
     * @dataProvider brokenAnswers
     */
    public function testAnythingElseIsAGenericError(int $code, string $body): void
    {
        $r = $this->client(['code' => $code, 'body' => $body])->fetchFormConfigResult('bs', 'default');

        $this->assertNull($r['config']);
        $this->assertSame('error', $r['reason']);
    }

    public function testCheckTenantAcceptsKnownTenantEvenThoughTheProbeFormDoesNotExist(): void
    {
        $c = $this->client(['code' => 404]);

        $this->assertSame(['ok' => true, 'reason' => null, 'detail' => ''], $c->checkTenant('default'));
        $this->assertStringContainsString('form=__probe__&tenant=default', $c->urls[0]);
    }

    public function testCheckTenantReportsRejectedAndUnreachable(): void
    {
        $this->assertSame('unauthorized', $this->client(['code' => 401])->checkTenant('x')['reason']);
        $this->assertFalse($this->client(['code' => 401])->checkTenant('x')['ok']);
        $this->assertSame('unreachable', $this->client(['code' => 0, 'error' => 'refused'])->checkTenant('x')['reason']);
    }

    public function testHealthCheckGivesAReason(): void
    {
        $this->assertSame(['status' => 'ok', 'reason' => ''], $this->client(['code' => 200])->healthCheck());
        $this->assertSame(['status' => 'error', 'reason' => 'HTTP 503'], $this->client(['code' => 503])->healthCheck());
        $this->assertSame(
            ['status' => 'error', 'reason' => 'Connection refused'],
            $this->client(['code' => 0, 'error' => 'Connection refused'])->healthCheck()
        );
    }

    public function testBaseUrlIsExposedForDiagnostics(): void
    {
        $this->assertSame('http://backend.test/api', $this->client([])->baseUrl());
    }
}
