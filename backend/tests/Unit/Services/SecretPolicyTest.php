<?php
declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\HmacValidator;
use App\Services\SecretPolicy;
use PHPUnit\Framework\TestCase;

class SecretPolicyTest extends TestCase
{
    private string|false $oldEnv;

    protected function setUp(): void
    {
        parent::setUp();
        $this->oldEnv = getenv('APP_ENV');
        unset($_ENV['APP_ENV'], $_SERVER['APP_ENV']);
    }

    protected function tearDown(): void
    {
        $this->oldEnv === false ? putenv('APP_ENV') : putenv('APP_ENV=' . $this->oldEnv);
        unset($_ENV['APP_ENV'], $_SERVER['APP_ENV']);
        parent::tearDown();
    }

    public function testPlaceholdersAreNeverAcceptable(): void
    {
        foreach ([true, false] as $production) {
            $this->assertFalse(SecretPolicy::isAcceptable('', $production));
            $this->assertFalse(SecretPolicy::isAcceptable('   ', $production));
            $this->assertFalse(SecretPolicy::isAcceptable('CHANGE_ME_IN_PRODUCTION', $production));
        }
    }

    public function testDevDefaultOnlyAcceptableOutsideProduction(): void
    {
        $this->assertTrue(SecretPolicy::isAcceptable('dev-api-key-replace-in-production', false));
        $this->assertFalse(SecretPolicy::isAcceptable('dev-api-key-replace-in-production', true));
    }

    public function testRealSecretIsAcceptableEverywhere(): void
    {
        $secret = bin2hex(random_bytes(32));
        $this->assertTrue(SecretPolicy::isAcceptable($secret, true));
        $this->assertTrue(SecretPolicy::isAcceptable($secret, false));
    }

    public function testProductionIsTheSafeDefaultWhenAppEnvUnset(): void
    {
        putenv('APP_ENV');
        $this->assertTrue(SecretPolicy::isProduction());
        putenv('APP_ENV=development');
        $this->assertFalse(SecretPolicy::isProduction());
    }

    public function testValidatorRejectsCorrectlySignedRequestWithPlaceholderSecret(): void
    {
        $body = '{"form_key":"bs"}';
        $sig  = hash_hmac('sha256', $body, SecretPolicy::PLACEHOLDER);
        $v    = new HmacValidator(SecretPolicy::PLACEHOLDER);

        $this->assertFalse($v->validate($body, $sig));
        $this->assertFalse($v->validateUploadSignature('1', 'f', 'a.pdf', hash_hmac('sha256', '1:f:a.pdf', SecretPolicy::PLACEHOLDER)));
    }

    public function testValidatorRejectsDevDefaultInProductionOnly(): void
    {
        $secret = 'dev-api-key-replace-in-production';
        $body   = '{"x":1}';
        $sig    = hash_hmac('sha256', $body, $secret);

        putenv('APP_ENV=production');
        $this->assertFalse((new HmacValidator($secret))->validate($body, $sig));

        putenv('APP_ENV=development');
        $this->assertTrue((new HmacValidator($secret))->validate($body, $sig));
    }
}
