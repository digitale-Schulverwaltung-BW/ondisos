<?php
declare(strict_types=1);

namespace Tests\Integration\Forms;

use App\Controllers\SurveyPreviewController as P;
use App\Repositories\FormResourceRepository as Res;

/**
 * What the survey preview may show.
 *
 * @group integration
 */
class SurveyPreviewControllerTest extends FormEditorTestCase
{
    private function preview(): P
    {
        return new P($this->configs, $this->resources, $this->drafts);
    }

    private function form(): void
    {
        $this->configs->insert('bs', ['form' => 'bs.json', 'theme' => 't.json']);
    }

    public function testUnknownFormAndBadKeysAreNotFound(): void
    {
        $this->assertSame('not_found', $this->preview()->load('gibtsnicht', P::SOURCE_LIVE)['status']);
        $this->assertSame('not_found', $this->preview()->load('../x', P::SOURCE_LIVE)['status']);
    }

    public function testFormOfAnotherTenantIsNotFound(): void
    {
        $this->form();
        $this->resources->save(Res::KIND_SURVEY, 'bs.json', $this->surveyJson('geheim', 'email'), 'u');
        $this->asTenant($this->tenantB);

        $this->assertSame('not_found', $this->preview()->load('bs', P::SOURCE_LIVE)['status']);
    }

    public function testFormWithoutASurveyInTheBackendHasNothingToShow(): void
    {
        $this->form();
        $r = $this->preview()->load('bs', P::SOURCE_DRAFT);
        $this->assertSame('nothing', $r['status']);
        $this->assertFalse($r['has_live']);
        $this->assertFalse($r['has_draft']);
    }

    public function testLiveSurveyIsRenderedWithItsTheme(): void
    {
        $this->form();
        $this->resources->save(Res::KIND_SURVEY, 'bs.json', $this->surveyJson('Vorname', 'email'), 'u');
        $this->resources->save(Res::KIND_THEME, 't.json', '{"themeName":"x","cssVariables":{"--a":"1"}}', 'u');

        $r = $this->preview()->load('bs', P::SOURCE_LIVE);

        $this->assertSame('ok', $r['status']);
        $this->assertSame(P::SOURCE_LIVE, $r['source']);
        $this->assertTrue($r['theme_from_backend']);
        $this->assertStringContainsString('"themeName":"x"', $r['theme_json']);
        $this->assertStringContainsString('Vorname', $r['survey_json']);
    }

    public function testDraftIsPreferredWhenAskedForAndFallsBackToLiveWhenThereIsNone(): void
    {
        $this->form();
        $this->resources->save(Res::KIND_SURVEY, 'bs.json', $this->surveyJson('live', 'email'), 'u');

        $this->assertSame(P::SOURCE_LIVE, $this->preview()->load('bs', P::SOURCE_DRAFT)['source'], 'no draft yet');

        $this->drafts->save('bs', $this->surveyJson('entwurf', 'email'), null, 'u');
        $draft = $this->preview()->load('bs', P::SOURCE_DRAFT);
        $this->assertSame(P::SOURCE_DRAFT, $draft['source']);
        $this->assertStringContainsString('entwurf', $draft['survey_json']);
        $live = $this->preview()->load('bs', P::SOURCE_LIVE);
        $this->assertSame(P::SOURCE_LIVE, $live['source']);
        $this->assertStringContainsString('live', $live['survey_json']);
        $this->assertTrue($live['has_draft']);
    }

    public function testMissingThemeFallsBackToNoThemeAndSaysSo(): void
    {
        $this->form();
        $this->resources->save(Res::KIND_SURVEY, 'bs.json', $this->surveyJson('a', 'email'), 'u');

        $r = $this->preview()->load('bs', P::SOURCE_LIVE);

        $this->assertSame('ok', $r['status']);
        $this->assertFalse($r['theme_from_backend']);
        $this->assertSame('{}', $r['theme_json']);
    }

    public function testASurveyThatFailsTheValidatorsIsRefusedWithReasonsAndNothingToRender(): void
    {
        $this->form();
        // stored before the rules existed / by SQL:
        $this->resources->save(Res::KIND_SURVEY, 'bs.json', '{"pages":[{"elements":[{"type":"html","name":"h","html":"<img src=x onerror=alert(1)>"}]}]}', 'attacker');

        $r = $this->preview()->load('bs', P::SOURCE_LIVE);

        $this->assertSame('invalid', $r['status']);
        $this->assertNull($r['survey_json']);
        $this->assertNotEmpty($r['errors']);
    }

    public function testAThemeWithMarkupIsNotAppliedButTheSurveyStillRenders(): void
    {
        $this->form();
        $this->resources->save(Res::KIND_SURVEY, 'bs.json', $this->surveyJson('a', 'email'), 'u');
        $this->resources->save(Res::KIND_THEME, 't.json', '{"cssVariables":{"--x":"</style><script>alert(1)</script>"}}', 'u');

        $r = $this->preview()->load('bs', P::SOURCE_LIVE);

        $this->assertSame('ok', $r['status']);
        $this->assertFalse($r['theme_from_backend']);
        $this->assertSame('{}', $r['theme_json']);
        $this->assertNotEmpty($r['warnings']);
    }

    public function testEmbeddedJsonCannotBreakOutOfTheScriptElement(): void
    {
        $this->form();
        // "<" only inside text that passes the allowlist (a title), not as markup:
        $this->resources->save(Res::KIND_SURVEY, 'bs.json', '{"title":"a </script> b <!-- c","pages":[{"elements":[{"type":"text","name":"x","title":"1 < 2 & 3"}]}]}', 'u');

        $r = $this->preview()->load('bs', P::SOURCE_LIVE);

        $this->assertSame('ok', $r['status']);
        $this->assertStringNotContainsString('<', $r['survey_json']);
        $this->assertStringNotContainsString('>', $r['survey_json']);
        $this->assertStringNotContainsString('&', $r['survey_json']);
    }

    public function testRealSurveyAndThemePreview(): void
    {
        $this->configs->insert('bs', ['form' => 'bs.json', 'theme' => 'survey_theme.json']);
        $this->resources->save(Res::KIND_SURVEY, 'bs.json', (string)file_get_contents(__DIR__ . '/../../../../frontend/surveys/bs.json'), 'u');
        $this->resources->save(Res::KIND_THEME, 'survey_theme.json', (string)file_get_contents(__DIR__ . '/../../../../frontend/surveys/survey_theme.json'), 'u');

        $r = $this->preview()->load('bs', P::SOURCE_LIVE);

        $this->assertSame('ok', $r['status'], json_encode($r['errors']));
        $this->assertTrue($r['theme_from_backend']);
        $this->assertNotNull(json_decode($r['survey_json']));
    }
}
