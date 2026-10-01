<?php
declare(strict_types=1);

namespace Tests\Integration\Forms;

use App\Forms\ConflictException;
use App\Forms\FormConfigSchema as S;
use App\Forms\NotFoundException;
use App\Repositories\FormResourceRepository as Res;
use App\Repositories\FormRevisionRepository as Rev;
use App\Services\FormPublishService;

/**
 * Flows of the form editor against a real database: create → config → draft → publish → restore → delete.
 *
 * @group integration
 */
class FormPublishServiceTest extends FormEditorTestCase
{
    private FormPublishService $service;

    /** @var list<array{0:string,1:string,2:array<string,mixed>}> */
    private array $auditEvents = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->auditEvents = [];
        $this->service = $this->makeService($this->revisions);
    }

    private function makeService(Rev $revisions): FormPublishService
    {
        return new FormPublishService(
            $this->db,
            $this->configs,
            $this->resources,
            $this->drafts,
            $revisions,
            audit: function (string $event, string $formKey, array $details): void {
                $this->auditEvents[] = [$event, $formKey, $details];
            },
        );
    }

    private function createBs(): void
    {
        $result = $this->service->createForm('bs', ['version' => '1.0.0'], S::ROLE_TENANT, 'admin-a');
        $this->assertTrue($result->ok(), json_encode($result->validation->errors()));
    }

    /** @return list<string> */
    private function revisionKinds(string $form = 'bs'): array
    {
        return array_map(fn ($r) => $r['kind'] . ':' . ($r['note'] ?? ''), array_reverse($this->revisions->list($form)));
    }

    // ---- createForm -------------------------------------------------------------------------

    public function testCreateFormStoresDefaultsAndFirstRevision(): void
    {
        $result = $this->service->createForm('bs', ['notify_email' => 'schule@example.de'], S::ROLE_TENANT, 'admin-a');

        $this->assertTrue($result->ok());
        $stored = $this->configs->find('bs');
        $this->assertSame($result->sha256, $stored['sha256']);
        $this->assertSame('bs.json', $stored['config']['form']);
        $this->assertSame('survey_theme.json', $stored['config']['theme']);
        $this->assertSame(['schule@example.de'], $stored['config']['notify_email']);
        $this->assertSame(['config:Formular angelegt'], $this->revisionKinds());
        $this->assertSame('form_created', $this->auditEvents[0][0]);
    }

    public function testCreateFormRejectsBadKeyAndDuplicates(): void
    {
        foreach (['../x', 'Bs', '', 'a b'] as $key) {
            $this->assertFalse($this->service->createForm($key, [], S::ROLE_PLATFORM, 'u')->ok(), $key);
        }
        $this->createBs();
        $this->expectException(ConflictException::class);
        $this->service->createForm('bs', [], S::ROLE_PLATFORM, 'u');
    }

    public function testTenantAdminCannotChooseFileNamesButPlatformAdminCan(): void
    {
        $tenant = $this->service->createForm('a', ['form' => 'fremd.json'], S::ROLE_TENANT, 'u');
        $this->assertFalse($tenant->ok());
        $this->assertNull($this->configs->find('a'), 'nothing may be stored');

        $platform = $this->service->createForm('a', ['form' => 'fremd.json', 'theme' => 'mein_theme.json'], S::ROLE_PLATFORM, 'u');
        $this->assertTrue($platform->ok());
        $this->assertSame('fremd.json', $this->configs->find('a')['config']['form']);
    }

    // ---- saveConfig -------------------------------------------------------------------------

    public function testSaveConfigMergesCreatesRevisionAndAudits(): void
    {
        $this->createBs();
        $token = $this->configs->find('bs')['sha256'];

        $result = $this->service->saveConfig('bs', ['version' => '2.0.0', 'pdf' => ['enabled' => '1', 'footer_text' => 'Danke']], S::ROLE_TENANT, 'admin-a', $token);

        $this->assertTrue($result->ok());
        $config = $this->configs->find('bs')['config'];
        $this->assertSame('2.0.0', $config['version']);
        $this->assertTrue($config['pdf']['enabled']);
        $this->assertSame('bs.json', $config['form'], 'untouched field stays');
        $this->assertSame(['config:Formular angelegt', 'config:Konfiguration gespeichert'], $this->revisionKinds());
        $this->assertSame('form_config_saved', end($this->auditEvents)[0]);
    }

    public function testSaveConfigKeepsUnknownKeysSetBySql(): void
    {
        $this->configs->insert('bs', ['form' => 'bs.json', 'theme' => 't.json', 'future' => ['x' => 1]]);
        $this->service->saveConfig('bs', ['version' => '9'], S::ROLE_TENANT, 'u', null);
        $this->assertSame(['x' => 1], $this->configs->find('bs')['config']['future']);
    }

    public function testSaveConfigWithStaleTokenConflictsAndChangesNothing(): void
    {
        $this->createBs();
        $stale = $this->configs->find('bs')['sha256'];
        $this->service->saveConfig('bs', ['version' => '2'], S::ROLE_TENANT, 'u', $stale);

        try {
            $this->service->saveConfig('bs', ['version' => '3'], S::ROLE_TENANT, 'u', $stale);
            $this->fail('expected ConflictException');
        } catch (ConflictException) {
            $this->assertSame('2', $this->configs->find('bs')['config']['version']);
        }
    }

    public function testValidationErrorWritesNothing(): void
    {
        $this->createBs();
        $before = $this->configs->find('bs')['sha256'];
        $revsBefore = count($this->revisions->list('bs'));

        $result = $this->service->saveConfig('bs', ['version' => '2', 'notify_email' => 'kaputt'], S::ROLE_TENANT, 'u', $before);

        $this->assertFalse($result->ok());
        $this->assertSame($before, $this->configs->find('bs')['sha256']);
        $this->assertCount($revsBefore, $this->revisions->list('bs'));
    }

    public function testTenantAdminCannotSetLogoOrRepointTheSurvey(): void
    {
        $this->createBs();
        $result = $this->service->saveConfig('bs', ['pdf' => ['logo' => 'logo.png'], 'form' => 'other.json'], S::ROLE_TENANT, 'u', null);
        $this->assertFalse($result->ok());
        $this->assertArrayNotHasKey('logo', $this->configs->find('bs')['config']['pdf'] ?? []);
        $this->assertSame('bs.json', $this->configs->find('bs')['config']['form']);
    }

    public function testUnchangedConfigCreatesNoRevisionAndKeepsToken(): void
    {
        $this->createBs();
        $token = $this->configs->find('bs')['sha256'];
        $revs  = count($this->revisions->list('bs'));

        $result = $this->service->saveConfig('bs', ['version' => '1.0.0'], S::ROLE_TENANT, 'u', $token);

        $this->assertTrue($result->ok());
        $this->assertSame($token, $result->sha256);
        $this->assertCount($revs, $this->revisions->list('bs'));
    }

    public function testSaveConfigOfOtherTenantsFormIsNotFound(): void
    {
        $this->createBs();
        $this->asTenant($this->tenantB);
        $this->expectException(NotFoundException::class);
        $this->service->saveConfig('bs', ['version' => 'hijack'], S::ROLE_TENANT, 'evil', null);
    }

    public function testLegacyConfigGetsItsOldStateAsRevisionBeforeTheFirstEdit(): void
    {
        // Config seeded without the editor has no history yet.
        $this->configs->insert('bs', ['form' => 'bs.json', 'theme' => 't.json', 'version' => 'seed']);
        $this->service->saveConfig('bs', ['version' => 'edit'], S::ROLE_TENANT, 'u', null);

        $this->assertSame(['config:Stand vor der Änderung', 'config:Konfiguration gespeichert'], $this->revisionKinds());
    }

    // ---- draft & publish --------------------------------------------------------------------

    public function testInvalidDraftIsRejectedAndNotStored(): void
    {
        $this->createBs();
        $result = $this->service->saveDraft('bs', '{"pages":[{"elements":[{"type":"html","html":"<script>alert(1)</script>"}]}]}', 'u');

        $this->assertFalse($result->ok());
        $this->assertNull($this->drafts->find('bs'));
    }

    public function testDraftForUnknownFormIsNotFound(): void
    {
        $this->expectException(NotFoundException::class);
        $this->service->saveDraft('gibtsnicht', $this->surveyJson(), 'u');
    }

    public function testDraftDoesNotChangeTheLiveSurvey(): void
    {
        $this->createBs();
        $this->resources->save(Res::KIND_SURVEY, 'bs.json', $this->surveyJson('alt', 'email'), 'import');

        $this->assertTrue($this->service->saveDraft('bs', $this->surveyJson('neu', 'email'), 'u')->ok());

        $this->assertStringContainsString('alt', $this->resources->find(Res::KIND_SURVEY, 'bs.json')['content']);
        $this->assertStringContainsString('neu', $this->drafts->find('bs')['survey_json']);
    }

    public function testFirstPublishMakesSurveyLiveAndRemovesDraft(): void
    {
        $this->createBs();
        $this->service->saveDraft('bs', $this->surveyJson('Vorname', 'email'), 'u');

        $result = $this->service->publish('bs', 'admin-a');

        $this->assertTrue($result->ok());
        $this->assertNotNull($this->resources->find(Res::KIND_SURVEY, 'bs.json'));
        $this->assertNull($this->drafts->find('bs'));
        $this->assertSame(['config:Formular angelegt', 'survey:Veröffentlicht'], $this->revisionKinds());
    }

    public function testSecondPublishKeepsHistoryWithoutDuplicates(): void
    {
        $this->createBs();
        $this->service->saveDraft('bs', $this->surveyJson('v1', 'email'), 'u');
        $this->service->publish('bs', 'u');
        $this->service->saveDraft('bs', $this->surveyJson('v2', 'email'), 'u');
        $this->service->publish('bs', 'u', 'Neue Frage');

        $surveyRevs = $this->revisions->list('bs', Rev::KIND_SURVEY);
        $this->assertCount(2, $surveyRevs, 'old live state is already the newest revision: no duplicate');
        $this->assertSame('Neue Frage', $surveyRevs[0]['note']);
        $this->assertStringContainsString('v2', $this->resources->find(Res::KIND_SURVEY, 'bs.json')['content']);
    }

    public function testPublishOverImportedSurveyStoresTheImportedStateFirst(): void
    {
        $this->createBs();
        $this->resources->save(Res::KIND_SURVEY, 'bs.json', $this->surveyJson('imported', 'email'), 'import');
        $this->service->saveDraft('bs', $this->surveyJson('edited', 'email'), 'u');
        $this->service->publish('bs', 'u');

        $notes = array_column(array_reverse($this->revisions->list('bs', Rev::KIND_SURVEY)), 'note');
        $this->assertSame(['Stand vor der Änderung', 'Veröffentlicht'], $notes);
    }

    public function testPublishWithoutDraftIsNotFound(): void
    {
        $this->createBs();
        $this->expectException(NotFoundException::class);
        $this->service->publish('bs', 'u');
    }

    public function testPublishFailsWhenLiveSurveyChangedSinceTheDraftWasStarted(): void
    {
        $this->createBs();
        $this->resources->save(Res::KIND_SURVEY, 'bs.json', $this->surveyJson('basis', 'email'), 'u');
        $this->service->saveDraft('bs', $this->surveyJson('meine-aenderung', 'email'), 'anna');

        // Someone else publishes in the meantime.
        $this->resources->save(Res::KIND_SURVEY, 'bs.json', $this->surveyJson('fremde-aenderung', 'email'), 'ben');

        try {
            $this->service->publish('bs', 'anna');
            $this->fail('expected ConflictException');
        } catch (ConflictException) {
            $this->assertStringContainsString('fremde-aenderung', $this->resources->find(Res::KIND_SURVEY, 'bs.json')['content']);
            $this->assertNotNull($this->drafts->find('bs'), "anna's draft must survive the conflict");
        }
    }

    public function testPublishIsAtomicIfTheHistoryCannotBeWritten(): void
    {
        $this->createBs();
        $this->resources->save(Res::KIND_SURVEY, 'bs.json', $this->surveyJson('live', 'email'), 'u');
        $this->service->saveDraft('bs', $this->surveyJson('draft', 'email'), 'u');

        $failing = new class($this->db) extends Rev {
            public function add(string $formKey, string $kind, ?string $name, string $content, ?string $note, ?string $createdBy): int
            {
                if ($note === 'Veröffentlicht') {
                    throw new \RuntimeException('disk full');
                }
                return parent::add($formKey, $kind, $name, $content, $note, $createdBy);
            }
        };

        try {
            $this->makeService($failing)->publish('bs', 'u');
            $this->fail('expected RuntimeException');
        } catch (\RuntimeException $e) {
            $this->assertSame('disk full', $e->getMessage());
        }

        $this->assertStringContainsString('live', $this->resources->find(Res::KIND_SURVEY, 'bs.json')['content'], 'live survey must be unchanged');
        $this->assertNotNull($this->drafts->find('bs'), 'draft must still exist');
        $this->assertSame(['config:Formular angelegt'], $this->revisionKinds(), 'the "old state" revision was rolled back too');
    }

    public function testPublishUsesTheSurveyNameFromTheConfig(): void
    {
        $this->service->createForm('vabo', ['form' => 'vabo-2026.json'], S::ROLE_PLATFORM, 'u');
        $this->service->saveDraft('vabo', $this->surveyJson('x', 'email'), 'u');
        $this->service->publish('vabo', 'u');

        $this->assertNotNull($this->resources->find(Res::KIND_SURVEY, 'vabo-2026.json'));
        $this->assertNull($this->resources->find(Res::KIND_SURVEY, 'vabo.json'));
    }

    public function testPublishedSurveyOfOneTenantIsNotVisibleToTheOther(): void
    {
        $this->createBs();
        $this->service->saveDraft('bs', $this->surveyJson('nur-a', 'email'), 'u');
        $this->service->publish('bs', 'u');

        $this->asTenant($this->tenantB);
        $this->assertNull($this->resources->find(Res::KIND_SURVEY, 'bs.json'));
    }

    public function testLintWarningsAreReturnedButDoNotBlock(): void
    {
        $this->createBs();
        $this->service->saveConfig('bs', ['prefill_fields' => "Firma\nTippfehler"], S::ROLE_TENANT, 'u', null);

        $result = $this->service->saveDraft('bs', $this->surveyJson('Firma', 'email'), 'u');

        $this->assertTrue($result->ok());
        $about = array_values(array_filter($result->validation->warnings(), static fn (array $w): bool => $w['path'] === 'prefill_fields'));
        $this->assertCount(1, $about);
        $this->assertStringContainsString('Tippfehler', $about[0]['message']);
    }

    // ---- restoreRevision --------------------------------------------------------------------

    public function testRestoreSurveyRevisionMakesTheOldSurveyLiveAgain(): void
    {
        $this->createBs();
        $this->service->saveDraft('bs', $this->surveyJson('v1', 'email'), 'u');
        $this->service->publish('bs', 'u');
        $this->service->saveDraft('bs', $this->surveyJson('v2', 'email'), 'u');
        $this->service->publish('bs', 'u');
        $v1 = $this->revisions->list('bs', Rev::KIND_SURVEY)[1]['id'];

        $result = $this->service->restoreRevision('bs', $v1, S::ROLE_TENANT, 'u');

        $this->assertTrue($result->ok());
        $this->assertStringContainsString('v1', $this->resources->find(Res::KIND_SURVEY, 'bs.json')['content']);
        $latest = $this->revisions->list('bs', Rev::KIND_SURVEY)[0];
        $this->assertSame("Wiederhergestellt aus Revision #{$v1}", $latest['note']);
        $this->assertSame('form_rolled_back', end($this->auditEvents)[0]);
    }

    public function testRestoreConfigRevisionRestoresRemovedAndChangedFields(): void
    {
        $this->createBs();
        $this->service->saveConfig('bs', ['notify_email' => 'a@b.de'], S::ROLE_TENANT, 'u', null);
        $withoutPdf = $this->revisions->latest('bs', Rev::KIND_CONFIG)['id'];
        $this->service->saveConfig('bs', ['pdf' => ['enabled' => true, 'footer_text' => 'x'], 'version' => '9'], S::ROLE_TENANT, 'u', null);

        $result = $this->service->restoreRevision('bs', $withoutPdf, S::ROLE_TENANT, 'u');

        $this->assertTrue($result->ok(), json_encode($result->validation->errors()));
        $config = $this->configs->find('bs')['config'];
        $this->assertArrayNotHasKey('pdf', $config, 'fields added later must be removed again');
        $this->assertSame('1.0.0', $config['version']);
        $this->assertSame(['a@b.de'], $config['notify_email']);
    }

    public function testRestoreOfOtherTenantsRevisionIsNotFound(): void
    {
        $this->createBs();
        $id = $this->revisions->latest('bs', Rev::KIND_CONFIG)['id'];

        $this->asTenant($this->tenantB);
        $this->service->createForm('bs', [], S::ROLE_TENANT, 'b');
        $this->expectException(NotFoundException::class);
        $this->service->restoreRevision('bs', $id, S::ROLE_TENANT, 'evil');
    }

    public function testRestoreOfRevisionBelongingToAnotherFormIsNotFound(): void
    {
        $this->createBs();
        $this->service->createForm('zq', [], S::ROLE_TENANT, 'u');
        $zqRevision = $this->revisions->latest('zq', Rev::KIND_CONFIG)['id'];

        $this->expectException(NotFoundException::class);
        $this->service->restoreRevision('bs', $zqRevision, S::ROLE_TENANT, 'u');
    }

    public function testTenantAdminCannotRestoreAConfigThatChangesRestrictedFields(): void
    {
        $this->createBs();
        $this->service->saveConfig('bs', ['pdf' => ['logo' => 'logo.png']], S::ROLE_PLATFORM, 'root', null);
        $withLogo = $this->revisions->latest('bs', Rev::KIND_CONFIG)['id'];
        $this->service->saveConfig('bs', ['pdf' => ['logo' => '']], S::ROLE_PLATFORM, 'root', null);

        $result = $this->service->restoreRevision('bs', $withLogo, S::ROLE_TENANT, 'schul-admin');

        $this->assertFalse($result->ok());
        $this->assertArrayNotHasKey('logo', $this->configs->find('bs')['config']['pdf'] ?? []);
    }

    // ---- deleteForm -------------------------------------------------------------------------

    public function testFormWithSubmissionsCannotBeDeleted(): void
    {
        $this->createBs();
        $stmt = $this->db->prepare("INSERT INTO anmeldungen (tenant_id, formular, data) VALUES (?, 'bs', '{}')");
        $stmt->bind_param('i', $this->tenantA);
        $stmt->execute();

        $result = $this->service->deleteForm('bs', 'u');

        $this->assertFalse($result->ok());
        $this->assertNotNull($this->configs->find('bs'));
    }

    public function testSubmissionsOfAnotherTenantDoNotBlockDeletion(): void
    {
        $this->createBs();
        $stmt = $this->db->prepare("INSERT INTO anmeldungen (tenant_id, formular, data) VALUES (?, 'bs', '{}')");
        $stmt->bind_param('i', $this->tenantB);
        $stmt->execute();

        $this->assertTrue($this->service->deleteForm('bs', 'u')->ok());
    }

    public function testDeleteRemovesConfigAndDraftButKeepsHistoryAndPublishedSurvey(): void
    {
        $this->createBs();
        $this->service->saveDraft('bs', $this->surveyJson('x', 'email'), 'u');
        $this->service->publish('bs', 'u');
        $this->service->saveDraft('bs', $this->surveyJson('y', 'email'), 'u');

        $this->assertTrue($this->service->deleteForm('bs', 'u')->ok());

        $this->assertNull($this->configs->find('bs'));
        $this->assertNull($this->drafts->find('bs'));
        $this->assertNotNull($this->resources->find(Res::KIND_SURVEY, 'bs.json'));
        $this->assertNotEmpty($this->revisions->list('bs'));
        $this->assertSame('form_deleted', end($this->auditEvents)[0]);
    }

    public function testDeleteOfUnknownOrForeignFormIsNotFound(): void
    {
        $this->createBs();
        $this->asTenant($this->tenantB);
        $this->expectException(NotFoundException::class);
        $this->service->deleteForm('bs', 'evil');
    }

    // ---- audit hygiene ----------------------------------------------------------------------

    public function testAuditEventsNeverContainSurveyOrConfigContent(): void
    {
        $this->createBs();
        $this->service->saveConfig('bs', ['notify_email' => 'geheim@example.de'], S::ROLE_TENANT, 'u', null);
        $this->service->saveDraft('bs', $this->surveyJson('GeheimesFeld', 'email'), 'u');
        $this->service->publish('bs', 'u');

        $logged = json_encode($this->auditEvents);
        $this->assertStringNotContainsString('geheim@example.de', $logged);
        $this->assertStringNotContainsString('GeheimesFeld', $logged);
        $this->assertNotEmpty($this->auditEvents);
    }
}
