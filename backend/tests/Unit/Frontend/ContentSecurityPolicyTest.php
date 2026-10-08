<?php

declare(strict_types=1);

namespace Tests\Unit\Frontend;

use Frontend\Utils\ContentSecurityPolicy;
use PHPUnit\Framework\TestCase;

class ContentSecurityPolicyTest extends TestCase
{
    protected function setUp(): void
    {
        require_once __DIR__ . '/../../../../frontend/src/Utils/ContentSecurityPolicy.php';
    }

    public function testScriptsNeedTheNonceWithoutInlineOrEval(): void
    {
        $policy = ContentSecurityPolicy::policy('abc');

        preg_match('/script-src [^;]*/', $policy, $m);
        $this->assertSame("script-src 'self' 'nonce-abc'", $m[0]);
    }

    public function testFormPageStaysEmbeddableButClosesTheOtherHoles(): void
    {
        $policy = ContentSecurityPolicy::policy('n');

        $this->assertStringNotContainsString('frame-ancestors', $policy, 'Schulen binden das Formular per iframe ein');
        foreach (["default-src 'self'", "object-src 'none'", "base-uri 'self'", "form-action 'self'", "connect-src 'self'"] as $d) {
            $this->assertStringContainsString($d, $policy);
        }
    }

    public function testModes(): void
    {
        $this->assertSame('Content-Security-Policy', ContentSecurityPolicy::headerName('enforce'));
        $this->assertSame('Content-Security-Policy-Report-Only', ContentSecurityPolicy::headerName('report-only'));
        $this->assertNull(ContentSecurityPolicy::headerName('off'));
    }

    public function testTheFormPageSendsThePolicyAndNoncesItsInlineScript(): void
    {
        $php = (string)file_get_contents(__DIR__ . '/../../../../frontend/public/index.php');

        $this->assertStringContainsString('ContentSecurityPolicy::send()', $php);
        preg_match_all('/<script\b([^>]*)>/i', $php, $tags);
        foreach ($tags[1] as $attrs) {
            if (preg_match('/\ssrc\s*=/i', ' ' . $attrs) !== 1) {
                $this->assertStringContainsString('nonce=', $attrs, 'Inline-<script> ohne nonce');
            }
        }
        $this->assertDoesNotMatchRegularExpression('/\son(click|change|submit|load|error)\s*=/i', $php);
    }

    public function testSurveyHandlersGuardTheHoneypotCalls(): void
    {
        // Ein gecachtes altes survey-handler-base.js darf das Formular nicht verhindern
        foreach (['frontend/public/js/survey-handler.js', 'wordpress-plugin/assets/js/survey-handler-wp.js'] as $file) {
            $js = (string)file_get_contents(__DIR__ . '/../../../../' . $file);
            $this->assertStringContainsString("typeof this.addHoneypot === 'function'", $js, $file);
            $this->assertStringContainsString("typeof this.honeypotValue === 'function'", $js, $file);
        }
    }
}
