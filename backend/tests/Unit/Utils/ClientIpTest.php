<?php

declare(strict_types=1);

namespace Tests\Unit\Utils;

use App\Utils\ClientIp;
use PHPUnit\Framework\TestCase;

class ClientIpTest extends TestCase
{
    /** @param list<string> $trusted */
    private function resolve(?string $remote, ?string $xff, array $trusted): string
    {
        $server = [];
        if ($remote !== null) {
            $server['REMOTE_ADDR'] = $remote;
        }
        if ($xff !== null) {
            $server['HTTP_X_FORWARDED_FOR'] = $xff;
        }

        return ClientIp::resolve($server, ClientIp::parseTrustedProxies(implode(',', $trusted)));
    }

    public function testWithoutTrustedProxiesForwardedHeaderIsIgnored(): void
    {
        $this->assertSame('203.0.113.9', $this->resolve('203.0.113.9', '1.2.3.4', []));
    }

    public function testSpoofedHeaderFromUntrustedPeerIsIgnored(): void
    {
        $this->assertSame('198.51.100.7', $this->resolve('198.51.100.7', '1.2.3.4', ['10.0.0.1']));
    }

    public function testTrustedProxyYieldsForwardedClient(): void
    {
        $this->assertSame('203.0.113.50', $this->resolve('10.0.0.1', '203.0.113.50', ['10.0.0.1']));
    }

    public function testTrustedProxyWithoutHeaderYieldsRemoteAddr(): void
    {
        $this->assertSame('10.0.0.1', $this->resolve('10.0.0.1', null, ['10.0.0.1']));
        $this->assertSame('10.0.0.1', $this->resolve('10.0.0.1', '  ', ['10.0.0.1']));
    }

    public function testClientSuppliedLeftEntryDoesNotWin(): void
    {
        // Attacker sends "X-Forwarded-For: 1.1.1.1"; the proxy appends the real peer
        $this->assertSame('203.0.113.50', $this->resolve('10.0.0.1', '1.1.1.1, 203.0.113.50', ['10.0.0.1']));
    }

    public function testMultipleTrustedHopsAreSkippedFromTheRight(): void
    {
        $xff = '203.0.113.50, 10.0.0.5, 10.0.0.6';
        $this->assertSame('203.0.113.50', $this->resolve('10.0.0.1', $xff, ['10.0.0.0/24']));
    }

    public function testUntrustedHopStopsTheWalk(): void
    {
        $xff = '1.1.1.1, 203.0.113.50, 10.0.0.5';
        $this->assertSame('203.0.113.50', $this->resolve('10.0.0.1', $xff, ['10.0.0.0/24']));
    }

    public function testAllEntriesTrustedYieldsLeftmost(): void
    {
        $this->assertSame('10.0.0.9', $this->resolve('10.0.0.1', '10.0.0.9, 10.0.0.5', ['10.0.0.0/24']));
    }

    public function testCidrBoundaries(): void
    {
        $this->assertSame('9.9.9.9', $this->resolve('172.16.255.254', '9.9.9.9', ['172.16.0.0/12']));
        $this->assertSame('172.32.0.1', $this->resolve('172.32.0.1', '9.9.9.9', ['172.16.0.0/12']));
        $this->assertSame('9.9.9.9', $this->resolve('192.168.1.130', '9.9.9.9', ['192.168.1.128/25']));
        $this->assertSame('192.168.1.127', $this->resolve('192.168.1.127', '9.9.9.9', ['192.168.1.128/25']));
    }

    public function testSlashZeroTrustsEveryone(): void
    {
        $this->assertSame('9.9.9.9', $this->resolve('8.8.8.8', '9.9.9.9', ['0.0.0.0/0']));
    }

    public function testIpv6ProxyAndClient(): void
    {
        $this->assertSame(
            '2001:db8:1::5',
            $this->resolve('fd00::1', '2001:DB8:1:0:0:0:0:5', ['fd00::/8'])
        );
    }

    public function testIpv6CidrNonByteAlignedPrefix(): void
    {
        $this->assertSame('9.9.9.9', $this->resolve('2001:db8:7fff::1', '9.9.9.9', ['2001:db8::/33']));
        $this->assertSame('2001:db8:8000::1', $this->resolve('2001:db8:8000::1', '9.9.9.9', ['2001:db8::/33']));
    }

    public function testMixedIpFamiliesNeverMatch(): void
    {
        $this->assertSame('::1', $this->resolve('::1', '9.9.9.9', ['0.0.0.0/0']));
    }

    public function testIpv4MappedIpv6IsTreatedAsIpv4(): void
    {
        $this->assertSame('9.9.9.9', $this->resolve('::ffff:10.0.0.1', '9.9.9.9', ['10.0.0.1']));
        $this->assertSame('9.9.9.9', $this->resolve('10.0.0.1', '::ffff:9.9.9.9', ['10.0.0.1']));
    }

    /** @return array<string, array{string}> */
    public static function invalidHeaders(): array
    {
        return [
            'garbage' => ['not-an-ip'],
            'garbage on the right' => ['9.9.9.9, evil'],
            'empty entry' => ['9.9.9.9, '],
            'with port' => ['9.9.9.9:8080'],
            'octet too large' => ['999.1.1.1'],
            'injection' => ["9.9.9.9\r\nSet-Cookie: x"],
        ];
    }

    /** @dataProvider invalidHeaders */
    public function testInvalidHeaderFallsBackToRemoteAddr(string $header): void
    {
        $this->assertSame('10.0.0.1', $this->resolve('10.0.0.1', $header, ['10.0.0.1']));
    }

    public function testInvalidEntryLeftOfUntrustedClientIsNeverReached(): void
    {
        $this->assertSame('9.9.9.9', $this->resolve('10.0.0.1', 'garbage, 9.9.9.9', ['10.0.0.1']));
    }

    public function testMissingOrEmptyRemoteAddrIsUnknown(): void
    {
        $this->assertSame('unknown', $this->resolve(null, '9.9.9.9', ['0.0.0.0/0']));
        $this->assertSame('unknown', $this->resolve('', null, []));
    }

    public function testNonIpRemoteAddrIsReturnedAsIs(): void
    {
        $this->assertSame('unix:', $this->resolve('unix:', '9.9.9.9', ['0.0.0.0/0']));
    }

    public function testParseTrustedProxiesDropsInvalidEntries(): void
    {
        $parsed = ClientIp::parseTrustedProxies(' 10.0.0.1 , 192.168.0.0/16,,bogus,10.0.0.0/33,::1/129,10.0.0.0/x,fd00::/8');
        $this->assertSame(['10.0.0.1', '192.168.0.0/16', 'fd00::/8'], $parsed);
    }

    public function testInvalidConfigDoesNotTrustAnyone(): void
    {
        $this->assertSame('8.8.8.8', $this->resolve('8.8.8.8', '9.9.9.9', ['bogus', '1.2.3.0/40']));
    }

    public function testGetReadsTrustedProxiesFromEnvironment(): void
    {
        $server = ['REMOTE_ADDR' => '10.0.0.1', 'HTTP_X_FORWARDED_FOR' => '9.9.9.9'];
        $this->assertSame('10.0.0.1', ClientIp::get($server));

        $_ENV['TRUSTED_PROXIES'] = '10.0.0.0/8';
        try {
            $this->assertSame('9.9.9.9', ClientIp::get($server));
        } finally {
            unset($_ENV['TRUSTED_PROXIES']);
        }
    }
}
