<?php
declare(strict_types=1);

namespace Tests\Unit\Frontend;

use App\Services\HmacValidator;
use PHPUnit\Framework\TestCase;

/**
 * Frontend side of the signature on a smartphone (3.2.0a): SignatureRelay and BackendApiClient::signatureHandoff().
 */
class SignatureRelayTest extends TestCase
{
    private const SECRET = 'a-real-looking-tenant-secret-0123456789abcdef';
    private const SIGN_URL = 'https://schule.example/anmeldung/sign.php';

    protected function setUp(): void
    {
        require_once __DIR__ . '/../../../../frontend/src/Config/FormConfig.php';
        require_once __DIR__ . '/../../../../frontend/src/Services/BackendApiClient.php';
        require_once __DIR__ . '/../../../../frontend/src/Services/SignatureRelay.php';
        require_once __DIR__ . '/../../../../frontend/src/Utils/ClientIp.php';
    }

    /** Client whose backend answers with $code/$data and which records the call. */
    private function client(int $code, ?array $data): object
    {
        return new class($code, $data) extends \Frontend\Services\BackendApiClient {
            public ?string $action = null;
            /** @var array<string, mixed> */
            public array $params = [];

            public function __construct(private int $code, private ?array $data)
            {
                parent::__construct('http://backend.test/api', 'schule-a', 'secret');
            }

            public function signatureHandoff(string $action, array $params): array
            {
                $this->action = $action;
                $this->params = $params;
                return ['code' => $this->code, 'data' => $this->data];
            }
        };
    }

    private function relay(object $client, ?\Closure $title = null): \Frontend\Services\SignatureRelay
    {
        return new \Frontend\Services\SignatureRelay($client, $title);
    }

    public function testUnknownActionIsRejectedWithoutBackendCall(): void
    {
        $client = $this->client(200, ['ok' => true]);

        $result = $this->relay($client)->handle(['action' => 'delete'], true, self::SIGN_URL);

        $this->assertSame(400, $result['code']);
        $this->assertNull($client->action);
    }

    public function testCreateAndPollNeedCsrf(): void
    {
        foreach (['create', 'poll'] as $action) {
            $client = $this->client(200, ['ok' => true]);

            $result = $this->relay($client)->handle(['action' => $action], false, self::SIGN_URL);

            $this->assertSame(['code' => 403, 'body' => ['ok' => false, 'error' => 'csrf']], $result, $action);
            $this->assertNull($client->action);
        }
    }

    public function testInfoAndSubmitWorkWithoutCsrf(): void
    {
        foreach (['info', 'submit'] as $action) {
            $client = $this->client(200, ['ok' => true]);

            $result = $this->relay($client)->handle(['action' => $action, 'id' => 'x', 'token' => 'y'], false, self::SIGN_URL);

            $this->assertSame(200, $result['code'], $action);
            $this->assertSame($action, $client->action);
        }
    }

    public function testCreateUsesServerSignUrlNotTheBrowsers(): void
    {
        $client = $this->client(200, ['ok' => true, 'id' => 'i']);

        $this->relay($client)->handle([
            'action' => 'create', 'form_key' => 'bs', 'field_name' => 'u', 'field_title' => 'U',
            'width' => 300, 'height' => '200', 'sign_url' => 'https://evil.example/',
            'pen_color' => '#000', 'background_color' => 'transparent',
        ], true, self::SIGN_URL);

        $this->assertSame(self::SIGN_URL, $client->params['sign_url']);
        $this->assertSame(300, $client->params['width']);
        $this->assertSame(200, $client->params['height']);
        $this->assertSame('#000', $client->params['pen_color']);
        $this->assertSame('transparent', $client->params['background_color']);
    }

    public function testOnlyExpectedFieldsArePassedOn(): void
    {
        $client = $this->client(200, ['ok' => true]);

        $this->relay($client)->handle(['action' => 'poll', 'id' => 'i', 'key' => 'k', 'tenant' => 'other'], true, self::SIGN_URL);

        $this->assertSame(['id' => 'i', 'key' => 'k'], $client->params);
    }

    public function testResponseIsFilteredToExpectedFields(): void
    {
        $client = $this->client(200, ['ok' => true, 'school' => 'HHS', 'form_key' => 'bs', 'field_name' => 'secret_name',
            'field_title' => 'Unterschrift', 'width' => 300, 'height' => 200, 'expires_in' => 500]);

        $result = $this->relay($client, fn(string $k): string => 'Anmeldung ' . $k)
            ->handle(['action' => 'info', 'id' => 'i', 'token' => 't'], false, self::SIGN_URL);

        $this->assertSame([
            'ok' => true, 'school' => 'HHS', 'form_key' => 'bs', 'field_title' => 'Unterschrift',
            'width' => 300, 'height' => 200, 'expires_in' => 500, 'form_title' => 'Anmeldung bs',
        ], $result['body']);
    }

    public function testBackendErrorCodeAndStatusArePassedThrough(): void
    {
        $result = $this->relay($this->client(410, ['ok' => false, 'error' => 'expired']))
            ->handle(['action' => 'info', 'id' => 'i', 'token' => 't'], false, self::SIGN_URL);

        $this->assertSame(['code' => 410, 'body' => ['ok' => false, 'error' => 'expired']], $result);
    }

    /** @return array<string, array{int, ?array}> */
    public static function unavailableBackends(): array
    {
        return [
            'unreachable' => [0, null],
            'unauthorized (wrong secret)' => [401, ['ok' => false, 'error' => 'unauthorized']],
            'server error' => [500, ['ok' => false, 'error' => 'boom']],
            'not json' => [200, null],
        ];
    }

    /** @dataProvider unavailableBackends */
    public function testBackendProblemsBecomeUnavailable(int $code, ?array $data): void
    {
        $result = $this->relay($this->client($code, $data))->handle(['action' => 'info'], false, self::SIGN_URL);

        $this->assertSame(['code' => 502, 'body' => ['ok' => false, 'error' => 'unavailable']], $result);
    }

    public function testOddBackendErrorTextIsNotEchoed(): void
    {
        $result = $this->relay($this->client(400, ['ok' => false, 'error' => '<script>x</script>']))
            ->handle(['action' => 'info'], false, self::SIGN_URL);

        $this->assertSame('invalid_input', $result['body']['error']);
    }

    /** @return array<string, array{array<string,string>, string}> */
    public static function siblingUrls(): array
    {
        return [
            'root' => [['HTTP_HOST' => 'schule.example', 'SCRIPT_NAME' => '/api/signature.php', 'HTTPS' => 'on'], 'https://schule.example/sign.php'],
            'subdir' => [['HTTP_HOST' => 'schule.example', 'SCRIPT_NAME' => '/anmeldung/api/signature.php', 'HTTPS' => 'on'], 'https://schule.example/anmeldung/sign.php'],
            'proxy' => [['HTTP_HOST' => 'schule.example', 'SCRIPT_NAME' => '/api/signature.php', 'HTTP_X_FORWARDED_PROTO' => 'https'], 'https://schule.example/sign.php'],
            'plain http with port' => [['HTTP_HOST' => '192.168.1.5:8081', 'SCRIPT_NAME' => '/api/signature.php'], 'http://192.168.1.5:8081/sign.php'],
        ];
    }

    /**
     * @dataProvider siblingUrls
     * @param array<string,string> $server
     */
    public function testSiblingUrl(array $server, string $expected): void
    {
        $this->assertSame($expected, \Frontend\Services\SignatureRelay::siblingUrl($server, 'sign.php'));
    }

    public function testSurveyTitle(): void
    {
        $this->assertSame('Anmeldung BS', \Frontend\Services\SignatureRelay::surveyTitle('{"title":"Anmeldung BS"}'));
        $this->assertSame('Anmeldung', \Frontend\Services\SignatureRelay::surveyTitle('{"title":{"default":"Registration","de":"Anmeldung"}}'));
        $this->assertSame('', \Frontend\Services\SignatureRelay::surveyTitle('{"pages":[]}'));
    }

    public function testClientSignsBodyWithPurposeSignature(): void
    {
        $client = new class('http://backend.test/api', 'schule-a', self::SECRET) extends \Frontend\Services\BackendApiClient {
            public string $url = '';
            public string $body = '';
            /** @var list<string> */
            public array $headers = [];

            protected function httpPost(string $url, string $body, array $headers, int $timeoutSeconds): array
            {
                [$this->url, $this->body, $this->headers] = [$url, $body, $headers];
                return ['body' => '{"ok":true,"status":"open"}', 'code' => 200, 'error' => ''];
            }
        };

        $result = $client->signatureHandoff('poll', ['id' => 'i', 'key' => 'k']);

        $this->assertSame(['code' => 200, 'data' => ['ok' => true, 'status' => 'open']], $result);
        $this->assertSame('http://backend.test/api/signature-handoff.php?tenant=schule-a', $client->url);
        $this->assertSame('{"action":"poll","id":"i","key":"k"}', $client->body);
        $h = [];
        foreach ($client->headers as $line) {
            [$name, $value] = array_map('trim', explode(':', $line, 2));
            $h[$name] = $value;
        }
        $this->assertTrue((new HmacValidator(self::SECRET))->validateV2(
            'signature', hash('sha256', $client->body), $h['X-Timestamp'], $h['X-Nonce'], $h['X-Signature']
        ));
    }
}
