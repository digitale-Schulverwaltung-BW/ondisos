<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\SignatureVerifier;
use PHPUnit\Framework\TestCase;

/**
 * Seit 3.1.3 ist "strict" der Standard für API_SIGNATURE_MODE (PLAN-3.1.3, AP1): Das alte, replay-fähige
 * Format 1 wird nur noch angenommen, wenn der Betreiber "compat" ausdrücklich setzt.
 */
class SignatureModeDefaultTest extends TestCase
{
    public function testStrictIsTheDefaultMode(): void
    {
        $this->assertSame(SignatureVerifier::MODE_STRICT, SignatureVerifier::DEFAULT_MODE);
    }

    public function testEnvExampleSetsStrict(): void
    {
        $env = (string)file_get_contents(__DIR__ . '/../../../.env.example');
        $this->assertMatchesRegularExpression('/^API_SIGNATURE_MODE=strict$/m', $env);
    }

    public function testUnsetOrInvalidEnvValueFallsBackToStrict(): void
    {
        $saved = $_ENV['API_SIGNATURE_MODE'] ?? null;
        try {
            foreach ([null, '', 'bogus'] as $value) {
                if ($value === null) {
                    unset($_ENV['API_SIGNATURE_MODE']);
                } else {
                    $_ENV['API_SIGNATURE_MODE'] = $value;
                }
                $verifier = SignatureVerifier::fromEnv('secret', 1);
                $mode = (new \ReflectionProperty($verifier, 'mode'))->getValue($verifier);
                $this->assertSame(SignatureVerifier::MODE_STRICT, $mode, 'Wert: ' . var_export($value, true));
            }
        } finally {
            if ($saved === null) {
                unset($_ENV['API_SIGNATURE_MODE']);
            } else {
                $_ENV['API_SIGNATURE_MODE'] = $saved;
            }
        }
    }

    public function testCompatCanBeChosenExplicitly(): void
    {
        $saved = $_ENV['API_SIGNATURE_MODE'] ?? null;
        try {
            $_ENV['API_SIGNATURE_MODE'] = 'compat';
            $verifier = SignatureVerifier::fromEnv('secret', 1);
            $this->assertSame(SignatureVerifier::MODE_COMPAT, (new \ReflectionProperty($verifier, 'mode'))->getValue($verifier));
        } finally {
            if ($saved === null) {
                unset($_ENV['API_SIGNATURE_MODE']);
            } else {
                $_ENV['API_SIGNATURE_MODE'] = $saved;
            }
        }
    }
}
