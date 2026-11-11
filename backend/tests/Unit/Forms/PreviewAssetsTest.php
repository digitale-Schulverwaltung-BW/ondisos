<?php
declare(strict_types=1);

namespace Tests\Unit\Forms;

use PHPUnit\Framework\TestCase;

/**
 * The preview carries its own copies of the SurveyJS runtime (the backend cannot see frontend/); they must not drift.
 * Fix a failure with: backend/tools/sync-preview-assets.sh
 */
class PreviewAssetsTest extends TestCase
{
    private const ROOT = __DIR__ . '/../../../..';

    /** @return array<string,array{0:string,1:string}> */
    public static function copies(): array
    {
        return [
            'survey core'    => ['frontend/public/assets/survey.core.min.js', 'backend/public/assets/preview/survey.core.min.js'],
            'survey ui'      => ['frontend/public/assets/survey-js-ui.min.js', 'backend/public/assets/preview/survey-js-ui.min.js'],
            'survey css'     => ['frontend/public/assets/survey-core.min.css', 'backend/public/assets/preview/survey-core.min.css'],
            'german locale'  => ['frontend/public/assets/i18n/german.min.js', 'backend/public/assets/preview/i18n/german.min.js'],
            'handler base'   => ['frontend/public/js/survey-handler-base.js', 'backend/public/assets/preview/survey-handler-base.js'],
            'font regular'   => ['frontend/public/assets/fonts/opensans/open-sans-v44-latin-regular.woff2', 'backend/public/assets/preview/fonts/open-sans-v44-latin-regular.woff2'],
            'font bold'      => ['frontend/public/assets/fonts/opensans/open-sans-v44-latin-700.woff2', 'backend/public/assets/preview/fonts/open-sans-v44-latin-700.woff2'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('copies')]
    public function testCopyIsIdenticalToTheFrontendFile(string $frontend, string $backend): void
    {
        $this->assertFileExists(self::ROOT . '/' . $backend);
        $this->assertSame(
            hash_file('sha256', self::ROOT . '/' . $frontend),
            hash_file('sha256', self::ROOT . '/' . $backend),
            "{$backend} differs from {$frontend}: run backend/tools/sync-preview-assets.sh"
        );
    }

    /** The preview sends nothing: it shares the handler base but must never offer signing on a smartphone (3.2.0a). */
    public function testPreviewDoesNotEnableSignatureHandoff(): void
    {
        $preview = (string)file_get_contents(self::ROOT . '/backend/public/assets/preview/survey-preview.js');

        $this->assertStringNotContainsString('setupSignatureHandoff', $preview);
    }

    public function testEveryScriptTagOfTheSandboxedFrameCarriesTheNonce(): void
    {
        $page = (string)file_get_contents(self::ROOT . '/backend/public/form_preview_frame.php');
        $html = (string)file_get_contents(self::ROOT . '/backend/inc/preview_frame.php'); // the frame's markup
        $php  = $page . $html;

        $this->assertStringContainsString('sandbox allow-scripts', $page, 'the frame must be sandboxed by its CSP header');
        $this->assertStringContainsString("script-src 'nonce-", $page);
        $this->assertStringContainsString("inc/preview_frame.php", $page);
        $this->assertSame(0, substr_count($php, '<script>'), 'a script tag without nonce would be blocked (and is a smell)');
        preg_match_all('/<script\b[^>]*>/', $html, $m);
        $this->assertNotEmpty($m[0]);
        foreach ($m[0] as $tag) {
            $this->assertStringContainsString('nonce=', $tag, $tag);
        }
        $this->assertStringNotContainsString('allow-same-origin', $php, 'allow-same-origin would give the survey the admin origin');
    }

    public function testTheOuterPageDoesNotGrantTheFrameItsOrigin(): void
    {
        foreach (['backend/public/form_preview.php', 'backend/public/form_preview_live.php', 'backend/inc/live_preview_window.php'] as $file) {
            $php = (string)file_get_contents(self::ROOT . '/' . $file);
            $this->assertStringNotContainsString('allow-same-origin', $php, $file);
            $this->assertStringNotContainsString('sandbox=', $php, "{$file}: the frame is sandboxed by its own header, an attribute could only loosen it");
        }
    }

    /** The live frame accepts messages from its parent only and versions its protocol (AP4a). */
    public function testTheLiveFrameOnlyListensToItsParent(): void
    {
        $js = (string)file_get_contents(self::ROOT . '/backend/public/assets/preview/survey-preview.js');

        $this->assertStringContainsString('event.source !== window.parent', $js);
        $this->assertStringContainsString("event.data.ondisos !== PROTOCOL", $js);
        $this->assertStringNotContainsString('innerHTML', $js);
        $this->assertStringNotContainsString('eval(', $js);
    }
}
