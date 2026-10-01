<?php
declare(strict_types=1);

namespace Tests\Integration\Forms;

use App\Forms\FormConfigSchema as S;
use App\Repositories\FormResourceRepository as Res;
use App\Repositories\TenantRepository;
use App\Services\FormCopyService;
use App\Services\FormPublishService;
use App\Services\SurveyImportService;

/**
 * Abuse limits and audit events of the form editor.
 *
 * @group integration
 */
class EditorLimitsAndAuditTest extends FormEditorTestCase
{
    /** @var list<array{0:string,1:string,2:array<string,mixed>}> */
    private array $audit = [];

    private function publishService(): FormPublishService
    {
        return new FormPublishService($this->db, $this->configs, $this->resources, $this->drafts, $this->revisions, audit: function (string $e, string $f, array $d): void {
            $this->audit[] = [$e, $f, $d];
        });
    }

    private function fillTenantWithForms(int $count): void
    {
        $stmt = $this->db->prepare("INSERT INTO form_configs (tenant_id, form_key, config_json) VALUES (?, ?, '{\"form\":\"x.json\",\"theme\":\"t.json\"}')");
        for ($i = 0; $i < $count; $i++) {
            $key = 'f' . $i;
            $stmt->bind_param('is', $this->tenantA, $key);
            $stmt->execute();
        }
    }

    public function testAtMostOneHundredFormsPerTenant(): void
    {
        $this->fillTenantWithForms(FormPublishService::MAX_FORMS_PER_TENANT - 1);
        $svc = $this->publishService();

        $this->assertTrue($svc->createForm('letztes', [], S::ROLE_TENANT, 'u')->ok(), 'the 100th form is fine');
        $over = $svc->createForm('zu-viel', [], S::ROLE_TENANT, 'u');

        $this->assertFalse($over->ok());
        $this->assertTrue($over->validation->hasErrorAt('form_key'));
        $this->assertNull($this->configs->find('zu-viel'));
        // another tenant is not affected by A's count
        $this->asTenant($this->tenantB);
        $this->assertTrue($this->publishService()->createForm('eins', [], S::ROLE_TENANT, 'u')->ok());
    }

    public function testCopyRefusesToExceedTheLimitAndWritesNothing(): void
    {
        $this->fillTenantWithForms(5);
        $this->asTenant($this->tenantB);
        $this->fillTenantWithFormsFor($this->tenantB, FormPublishService::MAX_FORMS_PER_TENANT - 2);
        $this->asTenant($this->tenantA);

        $tenants = new TenantRepository($this->db);
        $copy = new FormCopyService($this->db, $tenants, $this->configs, $this->resources, $this->revisions, audit: static function (): void {});

        try {
            $copy->copy($this->tenantA, $this->tenantB, null, false, S::ROLE_PLATFORM, 'root');
            $this->fail('expected InvalidArgumentException');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('mehr als', $e->getMessage());
        }
        $this->assertSame(FormPublishService::MAX_FORMS_PER_TENANT - 2, $this->countRows('form_configs', $this->tenantB));
    }

    private function fillTenantWithFormsFor(int $tenantId, int $count): void
    {
        $stmt = $this->db->prepare("INSERT INTO form_configs (tenant_id, form_key, config_json) VALUES (?, ?, '{}')");
        for ($i = 0; $i < $count; $i++) {
            $key = 'b' . $i;
            $stmt->bind_param('is', $tenantId, $key);
            $stmt->execute();
        }
    }

    public function testRejectedSurveyContentIsAuditedWithoutTheContent(): void
    {
        $svc = $this->publishService();
        $svc->createForm('bs', [], S::ROLE_TENANT, 'u');
        $this->audit = [];

        $svc->saveDraft('bs', '{"pages":[{"elements":[{"type":"html","name":"h","html":"<script>STEALTHY_PAYLOAD()</script>"}]}]}', 'u');

        $this->assertCount(1, $this->audit);
        [$event, $form, $details] = $this->audit[0];
        $this->assertSame('form_survey_rejected', $event);
        $this->assertSame('bs', $form);
        $this->assertGreaterThanOrEqual(1, $details['errors']);
        $this->assertSame(['pages[0].elements[0].html'], $details['paths']);
        $this->assertStringNotContainsString('STEALTHY_PAYLOAD', json_encode($this->audit));
    }

    public function testAcceptedDraftIsNotAuditedAsRejected(): void
    {
        $svc = $this->publishService();
        $svc->createForm('bs', [], S::ROLE_TENANT, 'u');
        $this->audit = [];

        $svc->saveDraft('bs', $this->surveyJson('a', 'email'), 'u');

        $this->assertSame(['form_draft_saved'], array_column($this->audit, 0));
    }

    public function testImportsAreAuditedWithHashButWithoutContent(): void
    {
        $events = [];
        $import = new SurveyImportService($this->resources, $this->configs, $this->revisions, audit: function (string $e, string $n, array $d) use (&$events): void {
            $events[] = [$e, $n, $d];
        });
        $good = $this->surveyJson('GEHEIMER_FELDNAME', 'email');

        $import->importResource(Res::KIND_SURVEY, 'a.json', $good, 'cli');
        $import->importResource(Res::KIND_SURVEY, 'b.json', '{"pages":[{"elements":[{"type":"html","name":"h","html":"<iframe src=x>"}]}]}', 'cli');
        $import->importResource(Res::KIND_SURVEY, 'c.json', $good, 'cli', dryRun: true);

        $this->assertSame(['survey_imported', 'survey_import_rejected'], array_column($events, 0), 'a dry run logs nothing');
        $this->assertSame(hash('sha256', $good), $events[0][2]['sha256']);
        $this->assertStringNotContainsString('GEHEIMER_FELDNAME', json_encode($events));
        $this->assertStringNotContainsString('iframe', json_encode($events));
    }

    public function testOversizedConfigIsRejectedAndNothingIsStored(): void
    {
        $svc = $this->publishService();
        $svc->createForm('bs', [], S::ROLE_PLATFORM, 'u');
        $before = $this->configs->find('bs')['sha256'];

        $big = ['prefill_fields' => array_map(static fn ($i) => str_repeat('x', 90) . $i, range(1, 90))];
        // a schema field is bounded by its own limits; an unknown key is not, so the overall limit must catch it:
        $current = $this->configs->find('bs')['config'];
        $current['zukunft'] = str_repeat('y', \App\Forms\FormConfigValidator::MAX_CONFIG_BYTES);
        $this->assertFalse((new \App\Forms\FormConfigValidator())->validate($current)->isValid());

        $this->assertSame($before, $this->configs->find('bs')['sha256']);
        $this->assertNotEmpty($big);
    }
}
