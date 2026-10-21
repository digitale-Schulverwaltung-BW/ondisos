<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\HmacValidator;
use App\Services\ReplayGuard;
use App\Services\SignatureVerifier;
use PHPUnit\Framework\TestCase;

class SignatureVerifierTest extends TestCase
{
    private const SECRET = 'tenant-secret-for-signature-tests-0123456789';
    private const NOW = 1_800_000_000;

    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/sigverify_' . uniqid();
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->dir);
    }

    private function verifier(string $mode = SignatureVerifier::MODE_COMPAT, int $now = self::NOW): SignatureVerifier
    {
        return new SignatureVerifier(
            new HmacValidator(self::SECRET),
            new ReplayGuard($this->dir, 600),
            $mode,
            300,
            1,
            fn(): int => $now
        );
    }

    /** @return array<string,string> $_SERVER-style headers of a v2 request */
    private function v2(string $purpose, string $payload, ?int $ts = null, string $nonce = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa'): array
    {
        $ts = (string)($ts ?? self::NOW);
        $sig = hash_hmac('sha256', HmacValidator::canonicalV2($purpose, $ts, $nonce, $payload), self::SECRET);

        return ['HTTP_X_TIMESTAMP' => $ts, 'HTTP_X_NONCE' => $nonce, 'HTTP_X_SIGNATURE' => $sig];
    }

    public function testValidV2SubmitIsAccepted(): void
    {
        $body = '{"form_key":"bs"}';

        $r = $this->verifier()->verifySubmit($body, $this->v2('submit', hash('sha256', $body)));

        $this->assertTrue($r['ok']);
        $this->assertSame(2, $r['version']);
    }

    public function testTamperedBodyIsRejected(): void
    {
        $server = $this->v2('submit', hash('sha256', '{"a":1}'));

        $r = $this->verifier()->verifySubmit('{"a":2}', $server);

        $this->assertFalse($r['ok']);
        $this->assertSame('bad_signature', $r['reason']);
    }

    public function testStaleAndFutureTimestampsAreRejected(): void
    {
        $body = '{}';
        $old = $this->v2('submit', hash('sha256', $body), self::NOW - 301);
        $future = $this->v2('submit', hash('sha256', $body), self::NOW + 301, 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb');

        $this->assertSame('timestamp_skew', $this->verifier()->verifySubmit($body, $old)['reason']);
        $this->assertSame('timestamp_skew', $this->verifier()->verifySubmit($body, $future)['reason']);
    }

    public function testRequestWithinTheWindowIsAccepted(): void
    {
        $body = '{}';
        $server = $this->v2('submit', hash('sha256', $body), self::NOW - 299);

        $this->assertTrue($this->verifier()->verifySubmit($body, $server)['ok']);
    }

    public function testReplayOfTheSameRequestIsRejected(): void
    {
        $body = '{"x":1}';
        $server = $this->v2('submit', hash('sha256', $body));
        $verifier = $this->verifier();

        $this->assertTrue($verifier->verifySubmit($body, $server)['ok']);
        $this->assertSame('replay', $verifier->verifySubmit($body, $server)['reason']);
    }

    public function testSignatureForAnotherPurposeIsRejected(): void
    {
        $slug = 'default';
        $server = $this->v2('forms', $slug);

        $this->assertFalse($this->verifier()->verifySubmit($slug, $server)['ok']);
    }

    public function testUploadSignatureBindsTheFileContent(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'up');
        file_put_contents($file, 'original');
        $payload = SignatureVerifier::uploadPayload('7', 'foto_0', 'a.pdf', hash('sha256', 'original'));
        $server = $this->v2('upload', $payload);

        $this->assertTrue($this->verifier()->verifyUpload('7', 'foto_0', 'a.pdf', $file, $server)['ok']);

        file_put_contents($file, 'swapped content');
        $server2 = $this->v2('upload', $payload, null, 'cccccccccccccccccccccccccccccccc');
        $this->assertFalse($this->verifier()->verifyUpload('7', 'foto_0', 'a.pdf', $file, $server2)['ok']);
        unlink($file);
    }

    public function testFormsRequestsNeedNoNonceStorage(): void
    {
        $server = $this->v2('forms', 'default');
        $verifier = $this->verifier();

        $this->assertTrue($verifier->verifyForms('default', $server)['ok']);
        $this->assertTrue($verifier->verifyForms('default', $server)['ok']);
    }

    public function testLegacySignatureIsAcceptedInCompatMode(): void
    {
        $body = '{"form_key":"bs"}';
        $server = ['HTTP_X_SIGNATURE' => hash_hmac('sha256', $body, self::SECRET)];

        $r = $this->verifier()->verifySubmit($body, $server);

        $this->assertTrue($r['ok']);
        $this->assertSame(1, $r['version']);
    }

    public function testLegacySignatureIsRejectedInStrictMode(): void
    {
        $body = '{"form_key":"bs"}';
        $server = ['HTTP_X_SIGNATURE' => hash_hmac('sha256', $body, self::SECRET)];

        $r = $this->verifier(SignatureVerifier::MODE_STRICT)->verifySubmit($body, $server);

        $this->assertFalse($r['ok']);
        $this->assertSame('legacy_not_allowed', $r['reason']);
    }

    public function testStrictModeStillAcceptsV2(): void
    {
        $body = '{}';

        $this->assertTrue(
            $this->verifier(SignatureVerifier::MODE_STRICT)->verifySubmit($body, $this->v2('submit', hash('sha256', $body)))['ok']
        );
    }

    public function testV2HeadersWithInvalidSignatureAreNotDowngradedToLegacy(): void
    {
        $body = '{"form_key":"bs"}';
        $server = [
            'HTTP_X_TIMESTAMP' => (string)self::NOW,
            'HTTP_X_NONCE' => 'dddddddddddddddddddddddddddddddd',
            // a valid legacy signature, but the request claims to be v2
            'HTTP_X_SIGNATURE' => hash_hmac('sha256', $body, self::SECRET),
        ];

        $this->assertFalse($this->verifier()->verifySubmit($body, $server)['ok']);
    }

    public function testMalformedTimestampOrNonceIsRejected(): void
    {
        $server = ['HTTP_X_TIMESTAMP' => 'yesterday', 'HTTP_X_NONCE' => 'x', 'HTTP_X_SIGNATURE' => 'abc'];

        $this->assertSame('malformed', $this->verifier()->verifySubmit('{}', $server)['reason']);
    }

    public function testMissingSignatureIsRejected(): void
    {
        $this->assertFalse($this->verifier()->verifySubmit('{}', [])['ok']);
    }

    public function testFrontendClientSignaturesAreAcceptedByTheBackend(): void
    {
        require_once __DIR__ . '/../../../../frontend/src/Config/FormConfig.php';
        require_once __DIR__ . '/../../../../frontend/src/Services/BackendApiClient.php';
        $client = new \Frontend\Services\BackendApiClient('http://backend.example.com/api', 'default', self::SECRET);
        $m = new \ReflectionMethod($client, 'signatureHeaders');

        $body = '{"form_key":"bs","data":{"Name":"Müller"}}';
        $headers = $m->invoke($client, 'submit', hash('sha256', $body), $body);
        $server = [];
        foreach ($headers as $line) {
            [$name, $value] = explode(': ', $line, 2);
            $server['HTTP_' . strtoupper(str_replace('-', '_', $name))] = $value;
        }

        $verifier = new SignatureVerifier(new HmacValidator(self::SECRET), new ReplayGuard($this->dir, 600));
        $r = $verifier->verifySubmit($body, $server);

        $this->assertTrue($r['ok']);
        $this->assertSame(2, $r['version']);
    }

    public function testFrontendPinnedToVersion1StillWorksInCompatMode(): void
    {
        require_once __DIR__ . '/../../../../frontend/src/Config/FormConfig.php';
        require_once __DIR__ . '/../../../../frontend/src/Services/BackendApiClient.php';
        putenv('API_SIGNATURE_VERSION=1');
        try {
            $client = new \Frontend\Services\BackendApiClient('http://backend.example.com/api', 'default', self::SECRET);
            $m = new \ReflectionMethod($client, 'signatureHeaders');
            $body = '{"x":1}';
            $headers = $m->invoke($client, 'submit', hash('sha256', $body), $body);
        } finally {
            putenv('API_SIGNATURE_VERSION');
        }

        $this->assertCount(1, $headers);
        $this->assertStringStartsWith('X-Signature: ', $headers[0]);
        $server = ['HTTP_X_SIGNATURE' => substr($headers[0], strlen('X-Signature: '))];
        $this->assertSame(1, $this->verifier()->verifySubmit($body, $server)['version']);
    }
    public function testSignatureHandoffAcceptsValidV2(): void
    {
        $body = '{"action":"poll"}';

        $r = $this->verifier()->verifySignatureHandoff($body, $this->v2('signature', hash('sha256', $body)));

        $this->assertTrue($r['ok']);
    }

    public function testSignatureHandoffRejectsLegacyEvenInCompatMode(): void
    {
        $body = '{"action":"poll"}';

        $r = $this->verifier(SignatureVerifier::MODE_COMPAT)
            ->verifySignatureHandoff($body, ['HTTP_X_SIGNATURE' => hash_hmac('sha256', $body, self::SECRET)]);

        $this->assertFalse($r['ok']);
        $this->assertSame('legacy_not_allowed', $r['reason']);
    }

    public function testSignatureHandoffRejectsSubmitSignature(): void
    {
        $body = '{"action":"poll"}';

        $r = $this->verifier()->verifySignatureHandoff($body, $this->v2('submit', hash('sha256', $body)));

        $this->assertFalse($r['ok']);
    }

    public function testSignatureHandoffRejectsReplay(): void
    {
        $body = '{"action":"poll"}';
        $headers = $this->v2('signature', hash('sha256', $body));
        $verifier = $this->verifier();

        $this->assertTrue($verifier->verifySignatureHandoff($body, $headers)['ok']);
        $this->assertSame('replay', $verifier->verifySignatureHandoff($body, $headers)['reason']);
    }
}
