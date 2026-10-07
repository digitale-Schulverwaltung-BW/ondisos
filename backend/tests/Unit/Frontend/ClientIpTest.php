<?php
declare(strict_types=1);

namespace Tests\Unit\Frontend;

use App\Utils\ClientIp as BackendClientIp;
use Frontend\Utils\ClientIp;
use PHPUnit\Framework\TestCase;

/**
 * Frontend\Utils\ClientIp is a port of the backend class; the detailed cases live in
 * Tests\Unit\Utils\ClientIpTest. Here: the essentials plus agreement between both copies.
 */
class ClientIpTest extends TestCase
{
    protected function setUp(): void
    {
        require_once __DIR__ . '/../../../../frontend/src/Utils/ClientIp.php';
    }

    protected function tearDown(): void
    {
        putenv('TRUSTED_PROXIES');
    }

    public function testForwardedHeaderIsIgnoredWithoutTrustedProxies(): void
    {
        putenv('TRUSTED_PROXIES');
        $server = ['REMOTE_ADDR' => '203.0.113.9', 'HTTP_X_FORWARDED_FOR' => '1.2.3.4'];
        $this->assertSame('203.0.113.9', ClientIp::get($server));
    }

    public function testOtherForwardingHeadersAreNeverUsed(): void
    {
        putenv('TRUSTED_PROXIES=0.0.0.0/0');
        $server = ['REMOTE_ADDR' => '203.0.113.9', 'HTTP_CLIENT_IP' => '1.2.3.4', 'HTTP_FORWARDED_FOR' => '5.6.7.8'];
        $this->assertSame('203.0.113.9', ClientIp::get($server));
    }

    public function testTrustedProxyFromEnvironmentYieldsForwardedClient(): void
    {
        putenv('TRUSTED_PROXIES=10.0.0.0/8');
        $server = ['REMOTE_ADDR' => '10.0.0.1', 'HTTP_X_FORWARDED_FOR' => '1.1.1.1, 203.0.113.50'];
        $this->assertSame('203.0.113.50', ClientIp::get($server));
    }

    /** @return array<string, array{array<string,string>, list<string>}> */
    public static function cases(): array
    {
        $trusted = ['10.0.0.0/24', 'fd00::/8'];
        return [
            'spoof untrusted' => [['REMOTE_ADDR' => '8.8.8.8', 'HTTP_X_FORWARDED_FOR' => '1.1.1.1'], $trusted],
            'multi hop' => [['REMOTE_ADDR' => '10.0.0.1', 'HTTP_X_FORWARDED_FOR' => '9.9.9.9, 10.0.0.5'], $trusted],
            'ipv6' => [['REMOTE_ADDR' => 'fd00::1', 'HTTP_X_FORWARDED_FOR' => '2001:db8::5'], $trusted],
            'garbage' => [['REMOTE_ADDR' => '10.0.0.1', 'HTTP_X_FORWARDED_FOR' => 'x, 9.9.9.9'], $trusted],
            'mapped' => [['REMOTE_ADDR' => '::ffff:10.0.0.1', 'HTTP_X_FORWARDED_FOR' => '9.9.9.9'], $trusted],
            'no remote' => [['HTTP_X_FORWARDED_FOR' => '9.9.9.9'], $trusted],
        ];
    }

    /**
     * @dataProvider cases
     * @param array<string,string> $server
     * @param list<string> $trusted
     */
    public function testFrontendAgreesWithBackend(array $server, array $trusted): void
    {
        putenv('TRUSTED_PROXIES=' . implode(',', $trusted));
        $this->assertSame(
            BackendClientIp::resolve($server, BackendClientIp::parseTrustedProxies(implode(',', $trusted))),
            ClientIp::get($server)
        );
    }
}
