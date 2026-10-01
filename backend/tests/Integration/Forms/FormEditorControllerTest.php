<?php
declare(strict_types=1);

namespace Tests\Integration\Forms;

use App\Controllers\FormEditorController;
use App\Forms\FormConfigSchema as S;
use App\Repositories\FormResourceRepository as Res;
use App\Services\FormPublishService;

/**
 * What the form editor pages do with a posted form, against a real database.
 *
 * @group integration
 */
class FormEditorControllerTest extends FormEditorTestCase
{
    private function controller(string $role = S::ROLE_TENANT, string $user = 'schul-admin'): FormEditorController
    {
        return new FormEditorController(
            $this->configs,
            $this->resources,
            $this->drafts,
            $this->revisions,
            new FormPublishService($this->db, $this->configs, $this->resources, $this->drafts, $this->revisions, audit: static function (): void {}),
            $role,
            $user,
        );
    }

    /** What a browser posts for the config form after the user changed a few things. */
    private function post(string $sha, array $cfg): array
    {
        return ['action' => 'save', 'sha256' => $sha, 'cfg' => $cfg];
    }

    public function testCreateThenListShowsTheFormWithDefaults(): void
    {
        $c = $this->controller();
        $out = $c->create(['form_key' => 'bs', 'cfg' => ['version' => '2026-1', 'notify_email' => 'sekretariat@example.de']]);

        $this->assertSame('created', $out['status']);
        $rows = $c->listForms();
        $this->assertCount(1, $rows);
        $this->assertSame('bs', $rows[0]['key']);
        $this->assertSame('2026-1', $rows[0]['version']);
        $this->assertSame('file', $rows[0]['survey_source']);
        $this->assertFalse($rows[0]['has_draft']);
        $this->assertSame(0, $rows[0]['submissions']);
    }

    public function testCreateRejectsBadAndDuplicateKeys(): void
    {
        $c = $this->controller();
        $this->assertSame('invalid', $c->create(['form_key' => '../x'])['status']);
        $this->assertSame('invalid', $c->create(['form_key' => ''])['status']);
        $this->assertSame('created', $c->create(['form_key' => 'bs'])['status']);
        $dup = $c->create(['form_key' => 'bs']);
        $this->assertSame('exists', $dup['status']);
        $this->assertNotEmpty($dup['result']->errors());
    }

    public function testListAndLoadOnlyShowTheOwnTenantsForms(): void
    {
        $this->controller()->create(['form_key' => 'nur-a']);
        $this->asTenant($this->tenantB);

        $this->assertSame([], $this->controller()->listForms());
        $this->assertNull($this->controller()->load('nur-a'), 'not found, not "forbidden"');
        $this->assertSame('not_found', $this->controller()->save('nur-a', $this->post('x', ['version' => 'hijack']))['status']);
        $this->assertSame('not_found', $this->controller()->delete('nur-a')['status']);
    }

    public function testSaveStoresTheChangeAndTheNextLoadShowsIt(): void
    {
        $c = $this->controller();
        $c->create(['form_key' => 'bs']);
        $sha = $c->load('bs')['sha256'];

        $out = $c->save('bs', $this->post($sha, [
            'version' => '2',
            'db' => '1',
            'notify_email' => "a@x.de\nb@x.de",
            'pdf' => ['enabled' => '1', 'footer_text' => 'Danke', 'include_fields__mode' => 'all', 'pre_sections' => [['title' => 'Hinweis', 'content' => 'Text'], ['title' => '', 'content' => '']]],
        ]));

        $this->assertSame('saved', $out['status'], json_encode($out['result']->errors()));
        $form = $c->load('bs');
        $this->assertSame('2', $form['config']['version']);
        $this->assertSame(['a@x.de', 'b@x.de'], $form['config']['notify_email']);
        $this->assertTrue($form['config']['pdf']['enabled']);
        $this->assertSame('all', $form['config']['pdf']['include_fields']);
        $this->assertCount(1, $form['config']['pdf']['pre_sections']);
        $this->assertSame(['a@x.de', 'b@x.de'], $form['values']['notify_email']);
        $this->assertNotSame($sha, $form['sha256']);
        $this->assertNotEmpty($form['revisions']);
    }

    public function testInvalidInputIsReportedPerFieldAndNothingIsSaved(): void
    {
        $c = $this->controller();
        $c->create(['form_key' => 'bs']);
        $before = $c->load('bs');

        $out = $c->save('bs', $this->post($before['sha256'], ['version' => 'ok', 'notify_email' => 'kaputt', 'pdf' => ['token_lifetime' => '5']]));

        $this->assertSame('invalid', $out['status']);
        $paths = array_column($out['result']->errors(), 'path');
        $this->assertContains('notify_email', $paths);
        $this->assertContains('pdf.token_lifetime', $paths);
        $this->assertSame($before['sha256'], $c->load('bs')['sha256'], 'valid fields of an invalid submission are not saved either');
        $this->assertSame('kaputt', $out['submitted']['notify_email'], 'so the page can show what was typed');
    }

    public function testTwoAdminsEditingTheSameFormTheSecondOneGetsAConflict(): void
    {
        $c = $this->controller();
        $c->create(['form_key' => 'bs']);
        $sha = $c->load('bs')['sha256'];

        $this->assertSame('saved', $c->save('bs', $this->post($sha, ['version' => 'anna']))['status']);
        $second = $c->save('bs', $this->post($sha, ['version' => 'ben']));

        $this->assertSame('conflict', $second['status']);
        $this->assertSame('anna', $c->load('bs')['config']['version']);
    }

    public function testMissingTokenStillSavesWithoutConflictCheck(): void
    {
        $c = $this->controller();
        $c->create(['form_key' => 'bs']);
        $this->assertSame('saved', $c->save('bs', ['cfg' => ['version' => 'x']])['status']);
    }

    public function testTenantAdminCannotChangeLogoOrFilesEvenWithACraftedPost(): void
    {
        $c = $this->controller(S::ROLE_PLATFORM, 'root');
        $c->create(['form_key' => 'bs', 'cfg' => ['pdf' => ['logo' => 'schule.png']]]);
        $tenantAdmin = $this->controller(S::ROLE_TENANT);
        $sha = $tenantAdmin->load('bs')['sha256'];

        $out = $tenantAdmin->save('bs', $this->post($sha, ['form' => 'fremd.json', 'theme' => 'x.json', 'pdf' => ['logo' => '../../.env']]));

        $this->assertSame('invalid', $out['status']);
        $form = $tenantAdmin->load('bs');
        $this->assertSame('bs.json', $form['config']['form']);
        $this->assertSame('schule.png', $form['config']['pdf']['logo']);
        $this->assertFalse($tenantAdmin->canEdit('pdf.logo'));
        $this->assertTrue($c->canEdit('pdf.logo'));
    }

    public function testDisabledInputsNotPostedByTheBrowserAreNotAnError(): void
    {
        $c = $this->controller(S::ROLE_PLATFORM, 'root');
        $c->create(['form_key' => 'bs', 'cfg' => ['pdf' => ['logo' => 'schule.png']]]);
        $tenantAdmin = $this->controller(S::ROLE_TENANT);

        $out = $tenantAdmin->save('bs', $this->post($tenantAdmin->load('bs')['sha256'], ['version' => 'neu']));

        $this->assertSame('saved', $out['status']);
        $this->assertSame('schule.png', $tenantAdmin->load('bs')['config']['pdf']['logo']);
    }

    public function testLoadOffersSurveyFieldsOnlyWhenTheSurveyIsInTheDatabase(): void
    {
        $c = $this->controller();
        $c->create(['form_key' => 'bs']);
        $this->assertNull($c->load('bs')['survey_fields']);
        $this->assertSame('file', $c->load('bs')['survey_source']);

        $this->resources->save(Res::KIND_SURVEY, 'bs.json', $this->surveyJson('Vorname', 'email'), 'u');

        $form = $c->load('bs');
        $this->assertSame(['Vorname', 'email'], $form['survey_fields']);
        $this->assertSame('database', $form['survey_source']);
        $this->assertSame('database', $c->listForms()[0]['survey_source']);
    }

    public function testDeleteIsRefusedWhileSubmissionsExistAndThenWorks(): void
    {
        $c = $this->controller();
        $c->create(['form_key' => 'bs']);
        $stmt = $this->db->prepare("INSERT INTO anmeldungen (tenant_id, formular, data) VALUES (?, 'bs', '{}')");
        $stmt->bind_param('i', $this->tenantA);
        $stmt->execute();

        $this->assertFalse($c->load('bs')['can_delete']);
        $this->assertSame('refused', $c->delete('bs')['status']);
        $this->assertNotNull($c->load('bs'));

        $this->db->query("DELETE FROM anmeldungen WHERE tenant_id = {$this->tenantA}");
        $this->assertSame('deleted', $c->delete('bs')['status']);
        $this->assertNull($c->load('bs'));
    }

    public function testRestoreBringsBackAnEarlierConfigAndTheHistoryRecordsIt(): void
    {
        $c = $this->controller();
        $c->create(['form_key' => 'bs', 'cfg' => ['version' => 'v1']]);
        $c->save('bs', $this->post($c->load('bs')['sha256'], ['version' => 'v2']));
        $oldest = end($c->load('bs')['revisions']);

        $out = $c->restore('bs', $oldest['id']);

        $this->assertSame('restored', $out['status'], json_encode($out['result']->errors()));
        $this->assertSame('v1', $c->load('bs')['config']['version']);
        $this->assertStringContainsString('Wiederhergestellt', $c->load('bs')['revisions'][0]['note']);
    }

    public function testRestoreOfAnotherTenantsRevisionIsNotFound(): void
    {
        $this->controller()->create(['form_key' => 'bs']);
        $revisionOfA = $this->controller()->load('bs')['revisions'][0]['id'];

        $this->asTenant($this->tenantB);
        $c = $this->controller();
        $c->create(['form_key' => 'bs']);

        $this->assertSame('not_found', $c->restore('bs', $revisionOfA)['status']);
    }

    public function testSaveReturnsLintWarningsAsWarningsNotErrors(): void
    {
        $c = $this->controller();
        $c->create(['form_key' => 'bs']);
        $this->resources->save(Res::KIND_SURVEY, 'bs.json', $this->surveyJson('Vorname', 'email'), 'u');

        $out = $c->save('bs', $this->post($c->load('bs')['sha256'], ['prefill_fields' => ['Vorname', 'Tippfehler']]));

        $this->assertSame('saved', $out['status']);
        $this->assertCount(1, $out['result']->warnings());
    }
}
