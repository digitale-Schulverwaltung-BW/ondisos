<?php

declare(strict_types=1);

namespace Tests\Unit\Public;

use PHPUnit\Framework\TestCase;

/**
 * Runs inc/auth.php in its own PHP process (it exits and sends headers) and checks the
 * network allowlist: access without login (Modell A) and running admin sessions.
 * (The CLI does not record header(), so redirects are verified by "script stopped" and session state.)
 */
class AdminNetworkGuardTest extends TestCase
{
    /**
     * @param array<string, string> $env
     * @param array<string, string> $server
     * @param array<string, mixed>  $session
     * @return array{out: string, code: int, session: array<string, mixed>|null}
     */
    private function runAuth(array $env, array $server, array $session = []): array
    {
        $root = dirname(__DIR__, 3);
        $code = <<<'PHP'
        require $argv[1] . '/vendor/autoload.php';
        $cfg = json_decode($argv[2], true);
        foreach ($cfg['env'] as $k => $v) { $_ENV[$k] = $v; }
        foreach ($cfg['server'] as $k => $v) { $_SERVER[$k] = $v; }
        register_shutdown_function(static function () {
            echo "\n@@" . json_encode([
                'code' => http_response_code(), 'session' => $_SESSION ?? null,
            ]);
        });
        session_start();
        $_SESSION = $cfg['session'];
        require $argv[1] . '/inc/auth.php';
        echo 'PASSED';
        PHP;

        $cmd = [PHP_BINARY, '-r', $code, '--', $root, json_encode(['env' => $env, 'server' => $server, 'session' => $session])];
        $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root, ['PATH' => (string)getenv('PATH')]);
        $stdout = (string)stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($proc);

        [$out, $json] = array_pad(explode("\n@@", $stdout, 2), 2, '{}');
        $meta = json_decode($json, true) ?: [];

        return [
            'out' => $out,
            'code' => (int)($meta['code'] ?? 0),
            'session' => $meta['session'] ?? null,
        ];
    }

    /** @return array<string, string> */
    private function noLoginEnv(string $networks): array
    {
        return ['AUTH_ENABLED' => 'false', 'MULTI_TENANT_ENABLED' => 'false', 'PLATFORM_ADMIN_ALLOWED_NETWORKS' => $networks];
    }

    public function testWithoutLoginAndEmptyListEveryoneGetsIn(): void
    {
        $r = $this->runAuth($this->noLoginEnv(''), ['REMOTE_ADDR' => '203.0.113.9']);
        $this->assertSame('PASSED', $r['out']);
    }

    public function testWithoutLoginAddressInListGetsIn(): void
    {
        $r = $this->runAuth($this->noLoginEnv('10.10.0.0/24'), ['REMOTE_ADDR' => '10.10.0.5']);
        $this->assertSame('PASSED', $r['out']);
    }

    public function testWithoutLoginForeignAddressGets403(): void
    {
        $r = $this->runAuth($this->noLoginEnv('10.10.0.0/24'), ['REMOTE_ADDR' => '192.0.2.1']);
        $this->assertStringNotContainsString('PASSED', $r['out']);
        $this->assertSame(403, $r['code']);
    }

    public function testForwardedForWithoutTrustedProxiesIsIgnored(): void
    {
        $r = $this->runAuth(
            $this->noLoginEnv('10.10.0.0/24'),
            ['REMOTE_ADDR' => '192.0.2.1', 'HTTP_X_FORWARDED_FOR' => '10.10.0.5']
        );
        $this->assertSame(403, $r['code']);
    }

    public function testForwardedForFromTrustedProxyIsUsed(): void
    {
        $env = $this->noLoginEnv('10.10.0.0/24') + ['TRUSTED_PROXIES' => '192.0.2.1'];
        $r = $this->runAuth($env, ['REMOTE_ADDR' => '192.0.2.1', 'HTTP_X_FORWARDED_FOR' => '10.10.0.5']);
        $this->assertSame('PASSED', $r['out']);
    }

    public function testRunningPlatformSessionFromForeignNetworkIsEnded(): void
    {
        $env = ['AUTH_ENABLED' => 'true', 'MULTI_TENANT_ENABLED' => 'false', 'PLATFORM_ADMIN_ALLOWED_NETWORKS' => '10.10.0.0/24'];
        $session = ['admin_logged_in' => true, 'is_platform_admin' => true, 'login_time' => time()];

        $r = $this->runAuth($env, ['REMOTE_ADDR' => '192.0.2.1'], $session);

        $this->assertStringNotContainsString('PASSED', $r['out']);
        $this->assertEmpty($r['session']['admin_logged_in'] ?? null);
    }

    public function testRunningPlatformSessionFromAllowedNetworkContinues(): void
    {
        $env = ['AUTH_ENABLED' => 'true', 'MULTI_TENANT_ENABLED' => 'false', 'PLATFORM_ADMIN_ALLOWED_NETWORKS' => '10.10.0.0/24'];
        $session = ['admin_logged_in' => true, 'is_platform_admin' => true, 'login_time' => time()];

        $r = $this->runAuth($env, ['REMOTE_ADDR' => '10.10.0.7'], $session);

        $this->assertSame('PASSED', $r['out']);
    }

    public function testPendingTwoFactorStateIsNotALogin(): void
    {
        $env = ['AUTH_ENABLED' => 'true', 'MULTI_TENANT_ENABLED' => 'false'];
        $r = $this->runAuth($env, ['REMOTE_ADDR' => '10.10.0.7'], ['pending_2fa' => ['user' => 'x', 'ts' => time()]]);

        $this->assertStringNotContainsString('PASSED', $r['out']);
    }
}
