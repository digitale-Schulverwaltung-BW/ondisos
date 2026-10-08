<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Config\Version;
use App\Services\SignatureVerifier;
use PHPUnit\Framework\TestCase;

/**
 * Erinnerung: Die Umstellungsphase des Signaturformats endet mit dem Release nach 3.1.2.
 * Ab 3.1.3 muss "strict" der Standard sein (Code und .env.example), sonst bleibt das alte, replay-fähige
 * Format unbemerkt dauerhaft zugelassen. Siehe docs/entwicklung/plans/PLAN-3.1.2.md (AP6) und TODO.md.
 */
class SignatureModeDefaultTest extends TestCase
{
    public function testStrictBecomesTheDefaultFromVersion313(): void
    {
        if (version_compare(Version::CURRENT, '3.1.3', '<')) {
            $this->assertSame(SignatureVerifier::MODE_COMPAT, SignatureVerifier::DEFAULT_MODE, 'Umstellungsphase: compat');
            return;
        }

        $this->assertSame(
            SignatureVerifier::MODE_STRICT,
            SignatureVerifier::DEFAULT_MODE,
            'Ab 3.1.3 ist "strict" der Standard für API_SIGNATURE_MODE (PLAN-3.1.2, AP6): DEFAULT_MODE, .env.example und Doku anpassen.'
        );
        $env = (string)file_get_contents(__DIR__ . '/../../../.env.example');
        $this->assertMatchesRegularExpression('/^API_SIGNATURE_MODE=strict$/m', $env);
    }
}
