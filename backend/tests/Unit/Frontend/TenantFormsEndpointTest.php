<?php
declare(strict_types=1);

namespace Tests\Unit\Frontend;

use App\Services\HmacValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The signed status endpoint forms.php: client side (BackendApiClient::fetchTenantForms) and the validator it talks to.
 */
class TenantFormsEndpointTest extends TestCase
{
    private const SECRET = 'a-real-looking-tenant-secret-0123456789abcdef';

    protected function setUp(): void
    {
        require_once __DIR__ . '/../../../../frontend/src/Config/FormConfig.php';
        require_once __DIR__ . '/../../../../frontend/src/Services/BackendApiClient.php';
    }

    /** @param array{code:int,body?:string,error?:string} $response */
    private function client(array $response, string $secret = self::SECRET): object
    {
        return new class('http://backend.test/api', 'schule-a', $secret, $response) extends \Frontend\Services\BackendApiClient {
            public ?string $url = null;
            /** @var list<string> */
            public array $headers = [];
            public int $calls = 0;

            /** @param array<string,mixed> $response */
            public function __construct(string $u, string $t, string $s, private array $response)
            {
                parent::__construct($u, $t, $s);
            }

            protected function httpRequest(string $url, int $timeoutSeconds, array $headers): array
            {
                $this->calls++;
                $this->url = $url;
                $this->headers = $headers;
                return ['code' => $this->response['code'], 'body' => $this->response['body'] ?? '', 'error' => $this->response['error'] ?? '', 'headers' => []];
            }
        };
    }

    public function testSignatureOverFormsAndSlugIsAcceptedByTheValidatorAndNothingElseIs(): void
    {
        $c = $this->client(['code' => 200, 'body' => '{"success":true,"count":1,"forms":["bs"]}']);
        $c->fetchTenantForms('schule-a');

        $this->assertSame('http://backend.test/api/forms.php?tenant=schule-a', $c->url);
        $sig = null;
        foreach ($c->headers as $h) {
            if (str_starts_with($h, 'X-Signature: ')) {
                $sig = substr($h, strlen('X-Signature: '));
            }
        }
        $this->assertNotNull($sig);

        $v = new HmacValidator(self::SECRET);
        $this->assertTrue($v->validateMessage('forms:schule-a', $sig));
        $this->assertFalse($v->validateMessage('forms:schule-b', $sig), 'bound to the slug');
        $this->assertFalse($v->validateMessage('schule-a', $sig), 'bound to the purpose');
        $this->assertFalse((new HmacValidator('another-secret-another-secret-123456'))->validateMessage('forms:schule-a', $sig));
    }

    public function testASubmitSignatureCannotBeReplayedForTheStatusEndpoint(): void
    {
        $body = '{"form":"bs"}';
        $submitSig = hash_hmac('sha256', $body, self::SECRET);
        $this->assertFalse((new HmacValidator(self::SECRET))->validateMessage('forms:schule-a', $submitSig));
        // and the other way round
        $formsSig = hash_hmac('sha256', 'forms:schule-a', self::SECRET);
        $this->assertFalse((new HmacValidator(self::SECRET))->validate($body, $formsSig));
    }

    /** @return array<string,array{0:string,1:string}> */
    public static function unusable(): array
    {
        return ['empty signature' => [self::SECRET, ''], 'placeholder secret' => ['CHANGE_ME_IN_PRODUCTION', 'x']];
    }

    #[DataProvider('unusable')]
    public function testValidatorRejectsEmptySignaturesAndPlaceholderSecrets(string $secret, string $sig): void
    {
        $valid = hash_hmac('sha256', 'forms:schule-a', $secret);
        $this->assertFalse((new HmacValidator($secret))->validateMessage('forms:schule-a', $sig === '' ? '' : $valid));
    }

    public function testSuccessReturnsTheFormKeys(): void
    {
        $r = $this->client(['code' => 200, 'body' => '{"success":true,"count":2,"forms":["bs","vabo",5]}'])->fetchTenantForms('schule-a');
        $this->assertTrue($r['ok']);
        $this->assertSame(['bs', 'vabo'], $r['forms'], 'non-strings are dropped');
    }

    public function testNoFormsIsASuccessWithAnEmptyList(): void
    {
        $r = $this->client(['code' => 200, 'body' => '{"success":true,"count":0,"forms":[]}'])->fetchTenantForms('schule-a');
        $this->assertTrue($r['ok']);
        $this->assertSame([], $r['forms']);
    }

    /** @return array<string,array{0:array<string,mixed>,1:string}> */
    public static function failures(): array
    {
        return [
            '401'            => [['code' => 401], 'unauthorized'],
            '403'            => [['code' => 403], 'unauthorized'],
            '404 old backend' => [['code' => 404], 'not_found'],
            '500'            => [['code' => 500], 'error'],
            'no response'    => [['code' => 0, 'error' => 'Connection refused'], 'unreachable'],
            'bad json'       => [['code' => 200, 'body' => '<html>'], 'error'],
            'no forms key'   => [['code' => 200, 'body' => '{"success":true}'], 'error'],
        ];
    }

    /** @param array{code:int,body?:string,error?:string} $response */
    #[DataProvider('failures')]
    public function testFailureReasons(array $response, string $reason): void
    {
        $r = $this->client($response)->fetchTenantForms('schule-a');
        $this->assertFalse($r['ok']);
        $this->assertSame($reason, $r['reason']);
        $this->assertSame([], $r['forms']);
    }

    public function testWithoutASecretNothingIsSent(): void
    {
        $c = $this->client(['code' => 200, 'body' => '{}'], '');
        $r = $c->fetchTenantForms('schule-a');
        $this->assertSame(0, $c->calls);
        $this->assertSame('unauthorized', $r['reason']);
    }
}
