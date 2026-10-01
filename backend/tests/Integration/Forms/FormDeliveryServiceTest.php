<?php
declare(strict_types=1);

namespace Tests\Integration\Forms;

use App\Forms\FormConfigSchema as S;
use App\Repositories\FormResourceRepository as Res;
use App\Services\FormDeliveryService;
use App\Services\FormPublishService;

/**
 * What the public endpoint delivers: published state only, scoped to the tenant, with a stable ETag.
 *
 * @group integration
 */
class FormDeliveryServiceTest extends FormEditorTestCase
{
    private FormDeliveryService $delivery;
    private FormPublishService $editor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->delivery = new FormDeliveryService($this->configs, $this->resources);
        $this->editor   = new FormPublishService($this->db, $this->configs, $this->resources, $this->drafts, $this->revisions, audit: static function (): void {});
    }

    public function testUnknownFormIsNull(): void
    {
        $this->assertNull($this->delivery->bundle('gibtsnicht'));
    }

    public function testFormWithoutDatabaseSurveyDeliversConfigAndNullSurvey(): void
    {
        $this->configs->insert('bs', ['form' => 'bs.json', 'theme' => 'survey_theme.json']);

        $bundle = $this->delivery->bundle('bs');

        $this->assertSame('bs.json', $bundle['config']['form']);
        $this->assertNull($bundle['survey_json'], 'frontend must fall back to its file');
        $this->assertNull($bundle['theme_json']);
    }

    public function testDeliversPublishedSurveyAndThemeByTheNamesInTheConfig(): void
    {
        $this->configs->insert('bs', ['form' => 'bs-2026.json', 'theme' => 'mein.json']);
        $this->resources->save(Res::KIND_SURVEY, 'bs-2026.json', '{"pages":[{"elements":[{"type":"text","name":"a"}]}],"x":{}}', 'u');
        $this->resources->save(Res::KIND_THEME, 'mein.json', '{"themeName":"x"}', 'u');
        $this->resources->save(Res::KIND_SURVEY, 'bs.json', '{"other":1}', 'u'); // not referenced

        $bundle = $this->delivery->bundle('bs');

        $this->assertSame('{"pages":[{"elements":[{"type":"text","name":"a"}]}],"x":{}}', $bundle['survey_json'], 'raw text, {} stays {}');
        $this->assertSame('{"themeName":"x"}', $bundle['theme_json']);
    }

    public function testSurveyAndThemeKindsAreNotMixedUp(): void
    {
        $this->configs->insert('bs', ['form' => 'x.json', 'theme' => 'x.json']);
        $this->resources->save(Res::KIND_SURVEY, 'x.json', '{"is":"survey"}', 'u');
        $this->resources->save(Res::KIND_THEME, 'x.json', '{"is":"theme"}', 'u');

        $bundle = $this->delivery->bundle('bs');

        $this->assertSame('{"is":"survey"}', $bundle['survey_json']);
        $this->assertSame('{"is":"theme"}', $bundle['theme_json']);
    }

    public function testNeverDeliversDraftsOrHistory(): void
    {
        $this->editor->createForm('bs', [], S::ROLE_TENANT, 'u');
        $this->resources->save(Res::KIND_SURVEY, 'bs.json', $this->surveyJson('live', 'email'), 'u');
        $this->editor->saveDraft('bs', $this->surveyJson('NUR_ENTWURF', 'email'), 'u');

        $bundle = $this->delivery->bundle('bs');

        $this->assertStringNotContainsString('NUR_ENTWURF', json_encode($bundle));
        $this->assertStringContainsString('live', $bundle['survey_json']);
    }

    public function testBundleOfOtherTenantIsInvisible(): void
    {
        $this->configs->insert('bs', ['form' => 'bs.json', 'theme' => 't.json']);
        $this->resources->save(Res::KIND_SURVEY, 'bs.json', '{"geheim":"A"}', 'u');

        $this->asTenant($this->tenantB);
        $this->assertNull($this->delivery->bundle('bs'));

        $this->configs->insert('bs', ['form' => 'bs.json', 'theme' => 't.json']);
        $this->assertNull($this->delivery->bundle('bs')['survey_json'], "B must not get A's survey just because the name matches");
    }

    public function testInvalidNamesInTheConfigDeliverNoResource(): void
    {
        $this->configs->insert('bs', ['form' => '../bs.json', 'theme' => 123]);
        $bundle = $this->delivery->bundle('bs');
        $this->assertNull($bundle['survey_json']);
        $this->assertNull($bundle['theme_json']);
    }

    public function testEtagChangesWhenConfigSurveyOrThemeChangeAndOnlyThen(): void
    {
        $this->configs->insert('bs', ['form' => 'bs.json', 'theme' => 't.json', 'version' => '1']);
        $e0 = $this->delivery->bundle('bs')['etag'];
        $this->assertSame($e0, $this->delivery->bundle('bs')['etag'], 'stable');

        $this->resources->save(Res::KIND_SURVEY, 'bs.json', '{"v":1}', 'u');
        $e1 = $this->delivery->bundle('bs')['etag'];
        $this->resources->save(Res::KIND_THEME, 't.json', '{"c":1}', 'u');
        $e2 = $this->delivery->bundle('bs')['etag'];
        $this->resources->save(Res::KIND_SURVEY, 'bs.json', '{"v":2}', 'u');
        $e3 = $this->delivery->bundle('bs')['etag'];
        $this->configs->update('bs', ['form' => 'bs.json', 'theme' => 't.json', 'version' => '2'], null);
        $e4 = $this->delivery->bundle('bs')['etag'];

        $this->assertCount(5, array_unique([$e0, $e1, $e2, $e3, $e4]));
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $e0);
    }

    public function testPublishingThroughTheEditorChangesTheEtag(): void
    {
        $this->editor->createForm('bs', [], S::ROLE_TENANT, 'u');
        $before = $this->delivery->bundle('bs')['etag'];
        $this->editor->saveDraft('bs', $this->surveyJson('a', 'email'), 'u');
        $this->assertSame($before, $this->delivery->bundle('bs')['etag'], 'a draft is not public');
        $this->editor->publish('bs', 'u');
        $this->assertNotSame($before, $this->delivery->bundle('bs')['etag']);
    }

    public function testFormKeysAreThoseOfTheCurrentTenantOnly(): void
    {
        $this->configs->insert('zq', ['form' => 'zq.json', 'theme' => 't.json']);
        $this->configs->insert('bs', ['form' => 'bs.json', 'theme' => 't.json']);
        $this->asTenant($this->tenantB);
        $this->configs->insert('nur-b', ['form' => 'b.json', 'theme' => 't.json']);

        $this->assertSame(['nur-b'], $this->delivery->formKeys());
        $this->asTenant($this->tenantA);
        $this->assertSame(['bs', 'zq'], $this->delivery->formKeys(), 'sorted, own tenant only');
    }

    public function testSanitizedWithholdsSurveysAndThemesThatFailTodaysValidators(): void
    {
        $this->configs->insert('bs', ['form' => 'bs.json', 'theme' => 't.json']);
        $this->resources->save(Res::KIND_SURVEY, 'bs.json', '{"pages":[{"elements":[{"type":"html","name":"h","html":"<img src=x onerror=alert(1)>"}]}]}', 'sql');
        $this->resources->save(Res::KIND_THEME, 't.json', '{"cssVariables":{"--x":"</style><script>1</script>"}}', 'sql');

        $out = $this->delivery->sanitized($this->delivery->bundle('bs'));

        $this->assertNull($out['survey_json']);
        $this->assertNull($out['theme_json']);
        $this->assertSame(['survey', 'theme'], array_column($out['rejected'], 'kind'));
        $this->assertStringNotContainsString('onerror', json_encode($out));
    }

    public function testSanitizedLeavesValidContentAlone(): void
    {
        $this->configs->insert('bs', ['form' => 'bs.json', 'theme' => 't.json']);
        $survey = $this->surveyJson('a', 'email');
        $this->resources->save(Res::KIND_SURVEY, 'bs.json', $survey, 'u');
        $this->resources->save(Res::KIND_THEME, 't.json', '{"themeName":"x"}', 'u');

        $out = $this->delivery->sanitized($this->delivery->bundle('bs'));

        $this->assertSame($survey, $out['survey_json']);
        $this->assertSame('{"themeName":"x"}', $out['theme_json']);
        $this->assertSame([], $out['rejected']);
    }

    public function testSanitizedAcceptsTheRealSurveysAndTheTheme(): void
    {
        $this->configs->insert('bs', ['form' => 'bs.json', 'theme' => 'survey_theme.json']);
        $this->resources->save(Res::KIND_SURVEY, 'bs.json', (string)file_get_contents(__DIR__ . '/../../../../frontend/surveys/bs.json'), 'u');
        $this->resources->save(Res::KIND_THEME, 'survey_theme.json', (string)file_get_contents(__DIR__ . '/../../../../frontend/surveys/survey_theme.json'), 'u');

        $out = $this->delivery->sanitized($this->delivery->bundle('bs'));

        $this->assertNotNull($out['survey_json']);
        $this->assertNotNull($out['theme_json']);
        $this->assertSame([], $out['rejected']);
    }
}
