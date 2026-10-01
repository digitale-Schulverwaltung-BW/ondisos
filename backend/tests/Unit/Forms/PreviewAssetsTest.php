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
            'survey css'     => ['frontend/public/assets/survey-core.fontless.min.css', 'backend/public/assets/preview/survey-core.fontless.min.css'],
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

    public function testEveryScriptTagOfTheSandboxedFrameCarriesTheNonce(): void
    {
        $php = (string)file_get_contents(self::ROOT . '/backend/public/form_preview_frame.php');

        $this->assertStringContainsString('sandbox allow-scripts', $php, 'the frame must be sandboxed by its CSP header');
        $this->assertStringContainsString("script-src 'nonce-", $php);
        $this->assertSame(0, substr_count($php, '<script>'), 'a script tag without nonce would be blocked (and is a smell)');
        preg_match_all('/<script\b[^>]*>/', $php, $m);
        $this->assertNotEmpty($m[0]);
        foreach ($m[0] as $tag) {
            $this->assertStringContainsString('nonce=', $tag, $tag);
        }
        $this->assertStringNotContainsString('allow-same-origin', $php, 'allow-same-origin would give the survey the admin origin');
    }

    public function testTheOuterPageDoesNotGrantTheFrameItsOrigin(): void
    {
        $php = (string)file_get_contents(self::ROOT . '/backend/public/form_preview.php');
        $this->assertStringNotContainsString('allow-same-origin', $php);
    }
}
