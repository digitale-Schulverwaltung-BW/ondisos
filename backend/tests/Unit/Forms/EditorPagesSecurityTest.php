<?php
declare(strict_types=1);

namespace Tests\Unit\Forms;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Structural guarantees of the 3.1 pages and endpoints that unit tests of services cannot give: every page that
 * changes something checks the CSRF token, every editor page goes through the access/tenant/rate-limit bootstrap,
 * and no public endpoint can hand out drafts or history.
 */
class EditorPagesSecurityTest extends TestCase
{
    private const PUBLIC = __DIR__ . '/../../../public';

    /** @return array<string,array{0:string}> */
    public static function editorPages(): array
    {
        return ['forms' => ['forms.php'], 'form_edit' => ['form_edit.php'], 'form_survey' => ['form_survey.php'], 'form_preview' => ['form_preview.php'], 'form_preview_frame' => ['form_preview_frame.php'],
                'form_preview_live' => ['form_preview_live.php'], 'form_preview_check' => ['form_preview_check.php'],
                'themes' => ['themes.php'], 'theme_edit' => ['theme_edit.php'], 'theme_preview_live' => ['theme_preview_live.php'],
                'theme_preview_check' => ['theme_preview_check.php']];
    }

    #[DataProvider('editorPages')]
    public function testEditorPagesRequireLoginAndTheEditorBootstrap(string $file): void
    {
        $php = (string)file_get_contents(self::PUBLIC . '/' . $file);

        $this->assertStringContainsString("inc/auth.php", $php, "{$file} must require the login");
        $this->assertStringContainsString("inc/form_editor.php", $php, "{$file} must go through access check, tenant check and rate limit");
        $this->assertStringNotContainsString('SKIP_AUTH_CHECK', $php);
    }

    /** @return array<string,array{0:string}> */
    public static function postingPages(): array
    {
        return ['forms' => ['forms.php'], 'form_edit' => ['form_edit.php'], 'form_survey' => ['form_survey.php'], 'theme_edit' => ['theme_edit.php']];
    }

    #[DataProvider('postingPages')]
    public function testPagesThatHandlePostCheckTheCsrfTokenBeforeActing(string $file): void
    {
        $php = (string)file_get_contents(self::PUBLIC . '/' . $file);

        $this->assertStringContainsString("REQUEST_METHOD'] === 'POST'", $php);
        $this->assertStringContainsString('csrf_validate()', $php);

        // The first csrf_validate() must come before the first state-changing call.
        $csrf = strpos($php, 'csrf_validate()');
        foreach (['$editor->save(', '$editor->create(', '$editor->delete(', '$editor->restore(', '$surveyEditor->saveDraft(', '$surveyEditor->publish(',
                  '$surveyEditor->discard(', '$surveyEditor->restore(', '$themeEditor->publish(', '$themeEditor->restore(', 'form_copy_run('] as $call) {
            $at = strpos($php, $call);
            if ($at !== false) {
                $this->assertGreaterThan($csrf, $at, "{$call} is called before the CSRF token is checked in {$file}");
            }
        }
    }

    public function testEveryFormFieldOfTheEditorPagesCarriesTheToken(): void
    {
        foreach (['forms.php', 'form_edit.php', 'form_survey.php', 'theme_edit.php'] as $file) {
            $php = (string)file_get_contents(self::PUBLIC . '/' . $file);
            $forms = preg_match_all('/<form\b[^>]*method="post"/i', $php);
            $tokens = substr_count($php, 'csrf_field()');
            $this->assertGreaterThanOrEqual($forms, $tokens, "{$file}: every POST form needs csrf_field()");
        }
    }

    public function testNoPublicEndpointCanDeliverDraftsOrHistory(): void
    {
        $files = glob(self::PUBLIC . '/api/*.php') ?: [];
        $this->assertNotEmpty($files);

        foreach ($files as $file) {
            $php = (string)file_get_contents($file);
            foreach (['FormDraftRepository', 'FormRevisionRepository', 'FormPublishService', 'FormCopyService', 'SurveyEditorController', 'ThemeService', 'ThemeEditorController'] as $forbidden) {
                $this->assertStringNotContainsString($forbidden, $php, basename($file) . " must not use {$forbidden}");
            }
        }
    }

    public function testTheUnsignedConfigEndpointDeliversOnlyThroughTheSanitizingService(): void
    {
        $php = (string)file_get_contents(self::PUBLIC . '/api/form-config.php');
        $this->assertStringContainsString('->sanitized(', $php);
        $this->assertLessThan(strpos($php, "'survey_json' =>"), strpos($php, '->sanitized('), 'sanitize before building the answer');
    }

    public function testTheSignedStatusEndpointChecksTheSignatureBeforeAnyData(): void
    {
        $php = (string)file_get_contents(self::PUBLIC . '/api/forms.php');
        $sig = strpos($php, 'verifyForms(');
        $data = strpos($php, 'formKeys()');
        $this->assertNotFalse($sig);
        $this->assertLessThan($data, $sig);
    }

    /** Live preview (AP4a): the check endpoint takes POST only, checks the token first, stores nothing. */
    public function testTheLivePreviewCheckIsPostOnlyChecksTheTokenAndStoresNothing(): void
    {
        $php = (string)file_get_contents(self::PUBLIC . '/form_preview_check.php');

        $post = strpos($php, "REQUEST_METHOD'] !== 'POST'");
        $csrf = strpos($php, 'csrf_validate()');
        $check = strpos($php, '->checkText(');
        $this->assertNotFalse($post);
        $this->assertNotFalse($csrf);
        $this->assertNotFalse($check);
        $this->assertLessThan($csrf, $post);
        $this->assertLessThan($check, $csrf);
        foreach (['saveDraft(', 'publish(', '->save(', 'discard(', 'restore(', '$surveyEditor'] as $call) {
            $this->assertStringNotContainsString($call, $php, "the live check must not store anything ({$call})");
        }
    }

    /** Live preview of the theme editor (3.2.0b AP3): the same rules as the survey's check endpoint. */
    public function testTheThemePreviewCheckIsPostOnlyChecksTheTokenAndStoresNothing(): void
    {
        $php = (string)file_get_contents(self::PUBLIC . '/theme_preview_check.php');

        $post  = strpos($php, "REQUEST_METHOD'] !== 'POST'");
        $csrf  = strpos($php, 'csrf_validate()');
        $check = strpos($php, '->previewVerdict(');
        $this->assertNotFalse($post);
        $this->assertNotFalse($csrf);
        $this->assertNotFalse($check);
        $this->assertLessThan($csrf, $post);
        $this->assertLessThan($check, $csrf);
        foreach (['publish(', '->save(', 'restore(', 'Restore('] as $call) {
            $this->assertStringNotContainsString($call, $php, "the theme check must not store anything ({$call})");
        }
        $scope = strpos($php, "define('EDITOR_RATE_LIMIT_SCOPE', 'preview')");
        $this->assertNotFalse($scope);
        $this->assertLessThan(strpos($php, 'inc/form_editor.php'), $scope);
    }

    /** Its many small checks count separately, so they cannot use up the allowance for saving and publishing. */
    public function testTheLivePreviewCheckHasItsOwnRateLimitBucket(): void
    {
        $php = (string)file_get_contents(self::PUBLIC . '/form_preview_check.php');

        $scope = strpos($php, "define('EDITOR_RATE_LIMIT_SCOPE', 'preview')");
        $this->assertNotFalse($scope);
        $this->assertLessThan(strpos($php, 'inc/form_editor.php'), $scope, 'the scope must be set before the editor bootstrap counts the request');
        $this->assertSame('preview', \App\Forms\EditorRateLimit::SCOPE_PREVIEW);
    }

    public function testTenantsPageStillRequiresThePlatformAdminBeforeCopying(): void
    {
        $php = (string)file_get_contents(self::PUBLIC . '/tenants.php');
        $guard = strpos($php, "is_platform_admin");
        $copy  = strpos($php, 'form_copy_run(');
        $this->assertLessThan($copy, $guard);
    }
}
