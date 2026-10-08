<?php

declare(strict_types=1);

namespace Tests\Unit\Utils;

use App\Utils\ContentSecurityPolicy;
use PHPUnit\Framework\TestCase;

class ContentSecurityPolicyTest extends TestCase
{
    public function testScriptsNeedTheNonceAndNeverAllowInline(): void
    {
        $policy = ContentSecurityPolicy::policy('abc123');

        $this->assertStringContainsString("script-src 'self' 'nonce-abc123'", $policy);
        preg_match("/script-src [^;]*/", $policy, $m);
        $this->assertStringNotContainsString('unsafe-inline', $m[0]);
        $this->assertStringNotContainsString('unsafe-eval', $m[0]);
    }

    public function testPolicyClosesTheUsualHoles(): void
    {
        $policy = ContentSecurityPolicy::policy('n');

        foreach (["default-src 'self'", "object-src 'none'", "base-uri 'self'", "form-action 'self'", "frame-ancestors 'self'"] as $directive) {
            $this->assertStringContainsString($directive, $policy);
        }
    }

    public function testNonceIsStablePerRequestAndUnguessable(): void
    {
        $nonce = ContentSecurityPolicy::nonce();

        $this->assertSame($nonce, ContentSecurityPolicy::nonce());
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]{22}$/', $nonce);
    }

    public function testModes(): void
    {
        $this->assertSame('Content-Security-Policy', ContentSecurityPolicy::headerName('enforce'));
        $this->assertSame('Content-Security-Policy', ContentSecurityPolicy::headerName('nonsense'));
        $this->assertSame('Content-Security-Policy-Report-Only', ContentSecurityPolicy::headerName('report-only'));
        $this->assertNull(ContentSecurityPolicy::headerName('off'));
        $this->assertNull(ContentSecurityPolicy::headerName('OFF'));
    }

    /**
     * Regel der Verwaltungsoberfläche: keine Inline-Handler, Inline-Skripte nur mit Nonce.
     * Sonst blockiert die CSP die Seite (oder jemand lockert sie, und der Schutz ist weg).
     */
    public function testAdminPagesHaveNoInlineHandlersAndNoncelessInlineScripts(): void
    {
        $files = array_merge(
            glob(__DIR__ . '/../../../public/*.php') ?: [],
            glob(__DIR__ . '/../../../inc/*.php') ?: []
        );
        $this->assertNotEmpty($files);

        foreach ($files as $file) {
            $php = (string)file_get_contents($file);
            $name = basename($file);

            $this->assertDoesNotMatchRegularExpression(
                '/\son(click|change|submit|input|load|error|keyup|keydown|focus|blur|mouseover)\s*=/i',
                $php,
                "$name enthält einen Inline-Handler: data-confirm, data-autosubmit oder assets/js/admin-ui.js nutzen"
            );

            preg_match_all('/<script\b([^>]*)>/i', $php, $tags);
            foreach ($tags[1] as $attrs) {
                if (preg_match('/\ssrc\s*=/i', ' ' . $attrs) === 1 || stripos($attrs, 'application/json') !== false) {
                    continue;
                }
                $this->assertStringContainsString('nonce=', $attrs, "$name: <script> ohne nonce");
            }
        }
    }
}
