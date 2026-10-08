<?php

declare(strict_types=1);

namespace Tests\Unit\Utils;

use App\Utils\ClientIdentity;
use PHPUnit\Framework\TestCase;

class ClientIdentityTest extends TestCase
{
    private function server(?string $client): array
    {
        $s = ['REMOTE_ADDR' => '10.0.0.5', 'HTTP_USER_AGENT' => 'curl'];
        if ($client !== null) {
            $s['HTTP_X_ONDISOS_CLIENT'] = $client;
        }

        return $s;
    }

    public function testVisitorsBehindOneFrontendGetDifferentIdentities(): void
    {
        $a = ClientIdentity::fingerprint($this->server(str_repeat('a', 64)), 1);
        $b = ClientIdentity::fingerprint($this->server(str_repeat('b', 64)), 1);

        $this->assertNotSame($a, $b);
    }

    public function testSameClientIdInDifferentTenantsDiffers(): void
    {
        $id = str_repeat('c', 64);

        $this->assertNotSame(
            ClientIdentity::fingerprint($this->server($id), 1),
            ClientIdentity::fingerprint($this->server($id), 2)
        );
    }

    public function testMalformedHeaderFallsBackToLegacyFingerprint(): void
    {
        $legacy = ClientIdentity::fingerprint($this->server(null), 1);

        $this->assertSame($legacy, ClientIdentity::fingerprint($this->server('not-a-hash'), 1));
        $this->assertSame($legacy, ClientIdentity::fingerprint($this->server(str_repeat('A', 64)), 1));
    }
}
