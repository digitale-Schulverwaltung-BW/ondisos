<?php
declare(strict_types=1);

namespace Tests\Integration\Forms;

use App\Controllers\SurveyEditorController;
use App\Forms\FormConfigSchema as S;
use App\Repositories\FormResourceRepository as Res;
use App\Services\FormPublishService;

/**
 * The survey editor flow against a real database: check → draft → publish → restore.
 *
 * @group integration
 */
class SurveyEditorControllerTest extends FormEditorTestCase
{
    private FormPublishService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new FormPublishService($this->db, $this->configs, $this->resources, $this->drafts, $this->revisions, audit: static function (): void {});
        $this->service->createForm('bs', ['version' => '2026-01-v1'], S::ROLE_TENANT, 'u');
    }

    private function editor(string $user = 'anna'): SurveyEditorController
    {
        return new SurveyEditorController($this->configs, $this->resources, $this->drafts, $this->revisions, $this->service, S::ROLE_TENANT, $user);
    }

    public function testFreshFormHasNoSurveyYetAndAnEmptyEditor(): void
    {
        $v = $this->editor()->load('bs');

        $this->assertNull($v['live']);
        $this->assertNull($v['draft']);
        $this->assertSame('', $v['editor_text']);
        $this->assertSame('bs.json', $v['survey_name']);
        $this->assertSame('2026-01-v2', $v['suggested_version']);
    }

    public function testCheckReportsSyntaxErrorsWithLineAndColumnAndStoresNothing(): void
    {
        $r = $this->editor()->check('bs', "{\n  \"pages\": [\n    {\"elements\": [{\"type\": \"text\" \"name\": \"a\"}]}\n  ]\n}");

        $this->assertFalse($r['valid']);
        $this->assertSame(3, $r['errors'][0]['line']);
        $this->assertNotNull($r['errors'][0]['column']);
        $this->assertNull($r['fields']);
        $this->assertNull($this->drafts->find('bs'));
    }

    public function testCheckPointsValidatorFindingsAtTheirLine(): void
    {
        $json = "{\n  \"pages\": [{\n    \"elements\": [\n      {\"type\": \"text\", \"name\": \"a\"},\n      {\"type\": \"html\", \"name\": \"h\", \"html\": \"<img src=x onerror=alert(1)>\"}\n    ]\n  }]\n}";

        $r = $this->editor()->check('bs', $json);

        $this->assertFalse($r['valid']);
        $e = $r['errors'][0];
        $this->assertSame('pages[0].elements[1].html', $e['path']);
        $this->assertSame(5, $e['line']);
    }

    public function testCheckOfAValidNewSurveyShowsAllFieldsAsAddedAndFirstPublish(): void
    {
        $r = $this->editor()->check('bs', $this->surveyJson('Vorname', 'email'));

        $this->assertTrue($r['valid']);
        $this->assertTrue($r['first_publish']);
        $this->assertSame(['Vorname', 'email'], $r['fields']['added']);
        $this->assertNull($r['diff']);
    }

    public function testCheckAgainstLiveShowsFieldChangesAndADiff(): void
    {
        $this->resources->save(Res::KIND_SURVEY, 'bs.json', $this->surveyJson('Vorname', 'Nachname', 'email'), 'u');

        $r = $this->editor()->check('bs', $this->surveyJson('Vorname', 'Familienname', 'email'));

        $this->assertTrue($r['valid']);
        $this->assertFalse($r['first_publish']);
        $this->assertSame(['Nachname'], $r['fields']['removed']);
        $this->assertSame(['Familienname'], $r['fields']['added']);
        $this->assertSame(1, $r['diff']['removed']);
        $this->assertSame(1, $r['diff']['added']);
    }

    public function testCheckWarnsAboutConfigNamesThatNoLongerExist(): void
    {
        $this->service->saveConfig('bs', ['prefill_fields' => "Firma\nVorname"], S::ROLE_TENANT, 'u', null);

        $r = $this->editor()->check('bs', $this->surveyJson('Vorname', 'email'));

        $this->assertTrue($r['valid']);
        $this->assertCount(1, $r['warnings']);
        $this->assertStringContainsString('Firma', $r['warnings'][0]['message']);
    }

    public function testCheckOfAnotherTenantsFormIsNull(): void
    {
        $this->asTenant($this->tenantB);
        $this->assertNull($this->editor()->check('bs', $this->surveyJson('a')));
        $this->assertNull($this->editor()->load('bs'));
    }

    public function testSaveDraftKeepsTheLiveSurveyAndShowsTheDraftInTheEditor(): void
    {
        $this->resources->save(Res::KIND_SURVEY, 'bs.json', $this->surveyJson('alt', 'email'), 'u');

        $out = $this->editor()->saveDraft('bs', $this->surveyJson('neu', 'email'));

        $this->assertSame('saved', $out['status']);
        $v = $this->editor()->load('bs');
        $this->assertStringContainsString('alt', $v['live']['content']);
        $this->assertStringContainsString('neu', $v['editor_text']);
        $this->assertFalse($v['draft']['stale']);
    }

    public function testInvalidTextIsNotSavedAsDraft(): void
    {
        $out = $this->editor()->saveDraft('bs', '{"pages":[{"elements":[{"type":"html","name":"h","html":"<script>x</script>"}]}]}');
        $this->assertSame('invalid', $out['status']);
        $this->assertNull($this->drafts->find('bs'));
    }

    public function testDraftBecomesStaleWhenSomeoneElsePublishes(): void
    {
        $this->resources->save(Res::KIND_SURVEY, 'bs.json', $this->surveyJson('basis', 'email'), 'u');
        $this->editor('anna')->saveDraft('bs', $this->surveyJson('anna', 'email'));

        $this->resources->save(Res::KIND_SURVEY, 'bs.json', $this->surveyJson('ben', 'email'), 'ben');

        $this->assertTrue($this->editor('anna')->load('bs')['draft']['stale']);
    }

    public function testPublishMakesTheTextLiveSetsTheVersionAndKeepsHistory(): void
    {
        $out = $this->editor()->publish('bs', $this->surveyJson('Vorname', 'email'), '2026-01-v2', 'Erste Fassung');

        $this->assertSame('published', $out['status']);
        $this->assertNull($out['version_error']);
        $v = $this->editor()->load('bs');
        $this->assertNotNull($v['live']);
        $this->assertNull($v['draft'], 'draft is consumed');
        $this->assertSame('2026-01-v2', $v['current_version']);
        $this->assertSame('Erste Fassung', $v['revisions'][0]['note']);
        $this->assertSame('2026-01-v3', $v['suggested_version']);
    }

    public function testPublishWithoutVersionLeavesTheVersionAlone(): void
    {
        $this->editor()->publish('bs', $this->surveyJson('a', 'email'), '', null);
        $this->assertSame('2026-01-v1', $this->editor()->load('bs')['current_version']);
    }

    public function testPublishWithInvalidTextChangesNothing(): void
    {
        $this->resources->save(Res::KIND_SURVEY, 'bs.json', $this->surveyJson('live', 'email'), 'u');

        $out = $this->editor()->publish('bs', '{kaputt', '2026-01-v9', null);

        $this->assertSame('invalid', $out['status']);
        $this->assertStringContainsString('live', $this->editor()->load('bs')['live']['content']);
        $this->assertSame('2026-01-v1', $this->editor()->load('bs')['current_version']);
    }

    public function testPublishRebasesOnTheCurrentLiveStateAndKeepsTheOthersVersionInHistory(): void
    {
        $this->resources->save(Res::KIND_SURVEY, 'bs.json', $this->surveyJson('basis', 'email'), 'u');
        $anna = $this->editor('anna');
        $anna->saveDraft('bs', $this->surveyJson('anna', 'email'));
        $this->resources->save(Res::KIND_SURVEY, 'bs.json', $this->surveyJson('ben', 'email'), 'ben');

        // Publishing re-saves the text as a draft first, which re-bases it on the current live state: no conflict.
        $out = $anna->publish('bs', $this->surveyJson('anna', 'email'), null, null);
        $this->assertSame('published', $out['status'], 'the editor shows the diff against live; publishing is an explicit decision');
        $this->assertStringContainsString('anna', $anna->load('bs')['live']['content']);
        // ... and the other person's version is in the history, not lost.
        $histories = array_map(fn ($r) => $this->revisions->find($r['id'])['content'], $anna->load('bs')['revisions']);
        $this->assertNotEmpty(array_filter($histories, fn ($c) => str_contains($c, 'ben')));
    }

    public function testDiscardRemovesOnlyTheDraft(): void
    {
        $this->resources->save(Res::KIND_SURVEY, 'bs.json', $this->surveyJson('live', 'email'), 'u');
        $this->editor()->saveDraft('bs', $this->surveyJson('entwurf', 'email'));

        $this->assertSame('discarded', $this->editor()->discard('bs'));
        $this->assertSame('no_draft', $this->editor()->discard('bs'));
        $v = $this->editor()->load('bs');
        $this->assertNull($v['draft']);
        $this->assertStringContainsString('live', $v['editor_text']);
    }

    public function testDiscardOfAnotherTenantsDraftIsNotFound(): void
    {
        $this->editor()->saveDraft('bs', $this->surveyJson('a', 'email'));
        $this->asTenant($this->tenantB);
        $this->assertSame('not_found', $this->editor()->discard('bs'));
        $this->asTenant($this->tenantA);
        $this->assertNotNull($this->drafts->find('bs'), "B must not be able to delete A's draft");
    }

    public function testRestoreBringsBackAnOlderSurvey(): void
    {
        $e = $this->editor();
        $e->publish('bs', $this->surveyJson('v1', 'email'), null, 'eins');
        $e->publish('bs', $this->surveyJson('v2', 'email'), null, 'zwei');
        $first = end($e->load('bs')['revisions']);

        $out = $e->restore('bs', $first['id']);

        $this->assertSame('restored', $out['status']);
        $this->assertStringContainsString('v1', $e->load('bs')['live']['content']);
    }

    public function testRealSurveyRoundTripThroughTheEditor(): void
    {
        $text = (string)file_get_contents(__DIR__ . '/../../../../frontend/surveys/bs.json');

        $check = $this->editor()->check('bs', $text);
        $this->assertTrue($check['valid'], json_encode($check['errors']));

        $out = $this->editor()->publish('bs', $text, null, null);
        $this->assertSame('published', $out['status']);
        $this->assertSame(trim($text), $this->editor()->load('bs')['live']['content'], 'stored exactly as pasted (trimmed)');

        $again = $this->editor()->check('bs', $text);
        $this->assertTrue($again['diff']['identical']);
        $this->assertSame([], $again['fields']['added'] + $again['fields']['removed']);
    }
}
