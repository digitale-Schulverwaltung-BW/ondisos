<?php

declare(strict_types=1);

namespace Tests\Unit\Public;

use PHPUnit\Framework\TestCase;

/**
 * The intermediate state "password correct, second factor missing" (pending_2fa) must not open any
 * protected page. inc/auth.php only accepts admin_logged_in; this test makes sure that every script
 * in public/ either includes it or is a known exception that handles pending_2fa itself.
 */
class TwoFactorScriptsTest extends TestCase
{
    /** Scripts that skip inc/auth.php on purpose (login flow, token and signed-API endpoints). */
    private const OPEN_SCRIPTS = [
        'login.php', 'logout.php', 'two_factor.php', 'two_factor_setup.php',
        'pdf/download.php',
        'api/health.php', 'api/form-config.php', 'api/forms.php', 'api/submit.php', 'api/upload.php', 'api/signature-handoff.php',
    ];

    /** @return list<string> */
    private function scripts(): array
    {
        $root = dirname(__DIR__, 3) . '/public';
        $files = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file->getExtension() === 'php' && !str_contains($file->getPathname(), '/assets/')) {
                $files[] = substr($file->getPathname(), strlen($root) + 1);
            }
        }
        sort($files);

        return $files;
    }

    private function source(string $script): string
    {
        return (string)file_get_contents(dirname(__DIR__, 3) . '/public/' . $script);
    }

    public function testEveryScriptIncludesAuthOrIsAKnownException(): void
    {
        foreach ($this->scripts() as $script) {
            $php = $this->source($script);
            if (in_array($script, self::OPEN_SCRIPTS, true)) {
                // The setup wizard requires inc/auth.php itself when there is no pending state
                if ($script !== 'two_factor_setup.php') {
                    $this->assertStringContainsString("define('SKIP_AUTH_CHECK'", $php, "$script is listed as open but does not say so");
                }
                continue;
            }
            $this->assertStringContainsString("inc/auth.php", $php, "$script is not listed as open, so it must require the login");
            $this->assertStringNotContainsString('pending_2fa', $php, "$script must not read the intermediate state");
        }
    }

    public function testOpenScriptListHasNoStaleEntries(): void
    {
        $scripts = $this->scripts();
        foreach (self::OPEN_SCRIPTS as $script) {
            $this->assertContains($script, $scripts, "$script no longer exists: remove it from the list");
        }
    }

    public function testOnlyTheTwoFactorPagesAndLoginUseTheIntermediateState(): void
    {
        foreach ($this->scripts() as $script) {
            $php = $this->source($script);
            if (str_contains($php, 'TwoFactorSession')) {
                $this->assertContains(
                    $script,
                    ['login.php', 'two_factor.php', 'two_factor_setup.php'],
                    "$script uses the intermediate state"
                );
            }
        }
    }

    public function testAuthPhpNeverReadsThePendingState(): void
    {
        $auth = (string)file_get_contents(dirname(__DIR__, 3) . '/inc/auth.php');
        $this->assertStringNotContainsString('pending_2fa', $auth);
        $this->assertStringNotContainsString('TwoFactorSession', $auth);
        $this->assertStringContainsString("empty(\$_SESSION['admin_logged_in'])", $auth);
    }

    public function testSetupPageRequiresLoginWhenThereIsNoPendingState(): void
    {
        $php = $this->source('two_factor_setup.php');
        $this->assertStringContainsString("require_once __DIR__ . '/../inc/auth.php'", $php);
        $this->assertStringContainsString('auth_page_require_pending', $php, 'network allowlist applies to the pending state');
    }

    public function testSessionIdIsRegeneratedBeforeTheLoginFlagIsSet(): void
    {
        $php = (string)file_get_contents(dirname(__DIR__, 3) . '/src/Services/LoginSession.php');
        $regenerate = strpos($php, 'session_regenerate_id(true)');
        $flag = strpos($php, "\$_SESSION['admin_logged_in'] = true");

        $this->assertNotFalse($regenerate);
        $this->assertNotFalse($flag);
        $this->assertLessThan($flag, $regenerate);
    }
}
