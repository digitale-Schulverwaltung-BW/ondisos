<?php
declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\HmacValidator;
use PHPUnit\Framework\TestCase;

/**
 * Request signing / tenant addressing of BackendApiClient.
 *
 * The signatures produced by the client must be accepted by the backend's
 * HmacValidator — these tests round-trip against the real validator so the
 * two sides cannot drift apart silently.
 */
class BackendApiClientSigningTest extends TestCase
{
    private const SECRET = 'tenant-secret-abc';

    protected function setUp(): void
    {
        parent::setUp();

        require_once __DIR__ . '/../../../../frontend/src/Config/FormConfig.php';
        require_once __DIR__ . '/../../../../frontend/src/Services/BackendApiClient.php';
    }

    private function client(?string $slug = 'schule-a', ?string $secret = self::SECRET): \Frontend\Services\BackendApiClient
    {
        return new \Frontend\Services\BackendApiClient('http://backend.example.com/api', $slug, $secret);
    }

    private function call(object $obj, string $method, mixed ...$args): mixed
    {
        $m = new \ReflectionMethod($obj, $method);
        $m->setAccessible(true);
        return $m->invoke($obj, ...$args);
    }

    public function testEndpointCarriesEncodedTenantSlug(): void
    {
        $this->assertSame(
            'http://backend.example.com/api/submit.php?tenant=schule-a',
            $this->call($this->client(), 'endpoint', 'submit.php')
        );
        $this->assertSame(
            'http://backend.example.com/api/upload.php?tenant=a%26b%3Dc',
            $this->call($this->client('a&b=c'), 'endpoint', 'upload.php')
        );
    }

    public function testBodySignatureIsAcceptedByHmacValidator(): void
    {
        $body = json_encode(['form_key' => 'bs', 'data' => ['Name' => 'Müller']]);
        $sig  = $this->call($this->client(), 'sign', $body);

        $this->assertTrue((new HmacValidator(self::SECRET))->validate($body, $sig));
    }

    public function testBodySignatureRejectedWithOtherTenantSecretOrTamperedBody(): void
    {
        $body = '{"form_key":"bs"}';
        $sig  = $this->call($this->client(), 'sign', $body);

        $this->assertFalse((new HmacValidator('other-tenant-secret'))->validate($body, $sig));
        $this->assertFalse((new HmacValidator(self::SECRET))->validate($body . ' ', $sig));
    }

    public function testUploadSignatureMatchesCanonicalString(): void
    {
        $sig = $this->call($this->client(), 'sign', '42:Zeugnis:zeugnis 2026.pdf');

        $this->assertTrue(
            (new HmacValidator(self::SECRET))->validateUploadSignature('42', 'Zeugnis', 'zeugnis 2026.pdf', $sig)
        );
        // Signature for another anmeldung id must not validate
        $this->assertFalse(
            (new HmacValidator(self::SECRET))->validateUploadSignature('43', 'Zeugnis', 'zeugnis 2026.pdf', $sig)
        );
    }

    public function testSubmitFailsWithoutSecretAndSendsNothing(): void
    {
        $client = new \Frontend\Services\BackendApiClient('http://127.0.0.1:1/api', 'schule-a', '');

        $result = $client->submitAnmeldung('bs', ['Name' => 'X'], []);

        $this->assertFalse($result['success']);
        $this->assertSame('Backend-Zugang nicht konfiguriert', $result['error']);
    }

    public function testDefaultsComeFromEnvironment(): void
    {
        putenv('TENANT_SLUG=env-schule');
        putenv('TENANT_API_SECRET=env-secret');
        try {
            $client = new \Frontend\Services\BackendApiClient('http://backend.example.com/api');

            $this->assertSame(
                'http://backend.example.com/api/submit.php?tenant=env-schule',
                $this->call($client, 'endpoint', 'submit.php')
            );
            $this->assertSame(hash_hmac('sha256', 'm', 'env-secret'), $this->call($client, 'sign', 'm'));
        } finally {
            putenv('TENANT_SLUG');
            putenv('TENANT_API_SECRET');
        }
    }
}
