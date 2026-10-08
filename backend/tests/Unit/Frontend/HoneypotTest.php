<?php

declare(strict_types=1);

namespace Tests\Unit\Frontend;

use Frontend\Utils\Honeypot;
use PHPUnit\Framework\TestCase;

class HoneypotTest extends TestCase
{
    protected function setUp(): void
    {
        require_once __DIR__ . '/../../../../frontend/src/Utils/Honeypot.php';
    }

    public function testEmptyOrMissingFieldIsNotTriggered(): void
    {
        $this->assertFalse(Honeypot::isTriggered([]));
        $this->assertFalse(Honeypot::isTriggered([Honeypot::FIELD => '']));
        $this->assertFalse(Honeypot::isTriggered([Honeypot::FIELD => '   ']));
    }

    public function testFilledFieldIsTriggered(): void
    {
        $this->assertTrue(Honeypot::isTriggered([Honeypot::FIELD => 'http://spam.example']));
    }

    public function testBotAnswerLooksLikeSuccess(): void
    {
        $this->assertSame(['success' => true], Honeypot::fakeSuccess());
    }

    public function testFieldNameMatchesTheJavaScriptHandlers(): void
    {
        foreach (['frontend/public/js/survey-handler-base.js', 'frontend/public/js/survey-handler.js', 'wordpress-plugin/assets/js/survey-handler-wp.js'] as $file) {
            $js = (string)file_get_contents(__DIR__ . '/../../../../' . $file);
            $this->assertStringContainsString(Honeypot::FIELD, $js, $file);
        }
    }
}
