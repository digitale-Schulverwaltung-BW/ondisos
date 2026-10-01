<?php
declare(strict_types=1);

namespace Tests\Integration\Forms;

use App\Config\TenantContext;
use App\Forms\AccessDeniedException;
use App\Forms\FormConfigSchema as S;
use App\Repositories\FormConfigRepository;
use App\Repositories\FormResourceRepository as Res;
use App\Repositories\FormRevisionRepository as Rev;
use App\Repositories\TenantRepository;
use App\Services\FormCopyService as Copy;

/**
 * Copying forms between tenants (onboarding of a new school).
 *
 * @group integration
 */
class FormCopyServiceTest extends FormEditorTestCase
{
    private int $tenantC;
    private string $slugA;
    /** @var list<array{0:string,1:string,2:array<string,mixed>}> */
    private array $audit = [];

    protected function setUp(): void
    {
        parent::setUp();
        $slug = 'it-forms-c-' . bin2hex(random_bytes(4));
        $secret = bin2hex(random_bytes(16));
        $stmt = $this->db->prepare('INSERT INTO tenants (name, slug, api_secret, active) VALUES (?, ?, ?, 1)');
        $name = 'IT ' . $slug;
        $stmt->bind_param('sss', $name, $slug, $secret);
        $stmt->execute();
        $this->tenantC = (int)$this->db->insert_id;
        $this->slugA   = (new TenantRepository($this->db))->findById($this->tenantA)['slug'];
        $this->audit   = [];
    }

    protected function tearDown(): void
    {
        $stmt = $this->db->prepare('DELETE FROM tenants WHERE id = ?');
        $stmt->bind_param('i', $this->tenantC);
        $stmt->execute();
        parent::tearDown();
    }

    private function service(?FormConfigRepository $configs = null): Copy
    {
        return new Copy(
            $this->db,
            new TenantRepository($this->db),
            $configs ?? $this->configs,
            $this->resources,
            $this->revisions,
            audit: function (string $event, string $form, array $details): void {
                $this->audit[] = [$event, $form, $details];
            },
        );
    }

    /** A realistic form of school A. */
    private function seedSource(): void
    {
        $this->configs->insert('bs', [
            'form' => 'bs.json', 'theme' => 'survey_theme.json', 'version' => '2026-01-v2', 'db' => true,
            'notify_email' => ['sekretariat@schule-a.de'],
            'prefill_fields' => ['Firma'],
            'email' => ['intro_template' => '{Vorname} wurde angemeldet'],
            'pdf' => ['enabled' => true, 'logo' => 'schule-a.png', 'header_title' => 'Schule A', 'footer_text' => 'Kontakt: Schule A', 'token_lifetime' => 1800],
            'zukunft' => ['x' => 1], // a key the schema does not know
        ]);
        $this->resources->save(Res::KIND_SURVEY, 'bs.json', $this->surveyJson('Vorname', 'Firma', 'email'), 'a');
        $this->resources->save(Res::KIND_THEME, 'survey_theme.json', '{"themeName":"x"}', 'a');
    }

    /** @return array<string,mixed> */
    private function targetConfig(string $key): array
    {
        return TenantContext::runAs($this->tenantB, fn () => $this->configs->find($key)['config']);
    }

    private function inTarget(callable $fn): mixed
    {
        return TenantContext::runAs($this->tenantB, $fn);
    }

    public function testCopiesConfigAndReferencedResourcesAndKeepsUnknownKeys(): void
    {
        $this->seedSource();

        $r = $this->service()->copy($this->tenantA, $this->tenantB, null, false, S::ROLE_PLATFORM, 'root');

        $this->assertSame(Copy::STATUS_COPIED, $r['forms']['bs']['status']);
        $c = $this->targetConfig('bs');
        $this->assertSame('bs.json', $c['form']);
        $this->assertSame('2026-01-v2', $c['version']);
        $this->assertSame(['Firma'], $c['prefill_fields']);
        $this->assertSame(['x' => 1], $c['zukunft'], 'unknown keys survive');
        $this->assertSame(1800, $c['pdf']['token_lifetime']);
        $this->inTarget(function (): void {
            $this->assertNotNull($this->resources->find(Res::KIND_SURVEY, 'bs.json'));
            $this->assertNotNull($this->resources->find(Res::KIND_THEME, 'survey_theme.json'));
        });
    }

    public function testRecipientAndLogoAreNeverCopiedAndSchoolTextsAreFlagged(): void
    {
        $this->seedSource();

        $r = $this->service()->copy($this->tenantA, $this->tenantB, null, false, S::ROLE_PLATFORM, 'root');

        $c = $this->targetConfig('bs');
        $this->assertArrayNotHasKey('notify_email', $c, "school A's secretariat must not receive school B's submissions");
        $this->assertArrayNotHasKey('logo', $c['pdf']);
        $this->assertTrue($r['forms']['bs']['recipient_cleared']);
        $this->assertEqualsCanonicalizing(['pdf.header_title', 'pdf.footer_text', 'email.intro_template'], $r['forms']['bs']['review']);
        $this->assertFalse($r['forms']['bs']['hidden_until_recipient'], 'db=true: the form works and stores, only the mail is missing');
    }

    public function testNothingOfTheSourceIsChangedAndTheTenantContextIsRestored(): void
    {
        $this->seedSource();
        $before = $this->configs->find('bs');

        $this->service()->copy($this->tenantA, $this->tenantB, null, false, S::ROLE_PLATFORM, 'root');

        $this->assertSame($before['sha256'], $this->configs->find('bs')['sha256']);
        $this->assertSame($this->tenantA, TenantContext::getTenantId(), 'context is back at the caller\'s tenant');
        $this->assertSame(0, $this->countRows('form_configs', $this->tenantC), 'third tenant untouched');
        $this->assertSame(0, $this->countRows('form_resources', $this->tenantC));
    }

    public function testContextIsRestoredToAllTenantsModeToo(): void
    {
        $this->seedSource();
        TenantContext::initAllTenants();

        $this->service()->copy($this->tenantA, $this->tenantB, null, false, S::ROLE_PLATFORM, 'root');

        $this->assertTrue(TenantContext::isAllTenants());
    }

    public function testSubmissionsDraftsAndHistoryOfTheSourceAreNotCopied(): void
    {
        $this->seedSource();
        $stmt = $this->db->prepare("INSERT INTO anmeldungen (tenant_id, formular, data, name, email) VALUES (?, 'bs', '{\"Vorname\":\"Anna\"}', 'Anna', 'anna@example.de')");
        $stmt->bind_param('i', $this->tenantA);
        $stmt->execute();
        $this->drafts->save('bs', $this->surveyJson('NUR_ENTWURF'), null, 'a');
        $this->revisions->add('bs', Rev::KIND_SURVEY, 'bs.json', '{"alt":1}', 'alt', 'a');

        $this->service()->copy($this->tenantA, $this->tenantB, null, false, S::ROLE_PLATFORM, 'root');

        $this->assertSame(0, $this->countRows('anmeldungen', $this->tenantB));
        $this->assertSame(0, $this->countRows('form_drafts', $this->tenantB));
        $this->inTarget(function (): void {
            $revs = $this->revisions->list('bs');
            $this->assertCount(1, $revs, 'the target starts with its own history: one entry');
            $this->assertSame("kopiert von {$this->slugA}", $revs[0]['note']);
            $this->assertSame(Rev::KIND_CONFIG, $revs[0]['kind']);
        });
    }

    public function testFormWithoutDbAndRecipientIsHiddenByTheFrontendUntilARecipientIsSet(): void
    {
        $this->configs->insert('info', ['form' => 'info.json', 'theme' => 't.json', 'db' => false, 'notify_email' => 'a@schule-a.de']);

        $r = $this->service()->copy($this->tenantA, $this->tenantB, null, false, S::ROLE_PLATFORM, 'root');

        $this->assertTrue($r['forms']['info']['hidden_until_recipient']);
        // The frontend rule (FormConfig::discardsSubmissions) must agree: the copy is not shown.
        require_once __DIR__ . '/../../../../frontend/src/Config/FormConfig.php';
        \Frontend\Config\FormConfig::load(['info' => $this->targetConfig('info')]);
        $this->assertTrue(\Frontend\Config\FormConfig::discardsSubmissions('info'));
        \Frontend\Config\FormConfig::load([]);
    }

    public function testExistingFormsAreSkippedAndNeverOverwrittenByDefault(): void
    {
        $this->seedSource();
        $this->inTarget(fn () => $this->configs->insert('bs', ['form' => 'bs.json', 'theme' => 't.json', 'version' => 'SCHULE-B']));

        $r = $this->service()->copy($this->tenantA, $this->tenantB, null, false, S::ROLE_PLATFORM, 'root');

        $this->assertSame(Copy::STATUS_SKIPPED, $r['forms']['bs']['status']);
        $this->assertSame('SCHULE-B', $this->targetConfig('bs')['version']);
        $this->inTarget(fn () => $this->assertNull($this->resources->find(Res::KIND_SURVEY, 'bs.json'), 'a skipped form copies no resources either'));
    }

    public function testOverwriteReplacesTheFormKeepsTheOldStateInHistoryAndSaysWhatItDidToResources(): void
    {
        $this->seedSource();
        $this->inTarget(function (): void {
            $this->configs->insert('bs', ['form' => 'bs.json', 'theme' => 'survey_theme.json', 'version' => 'SCHULE-B']);
            $this->resources->save(Res::KIND_SURVEY, 'bs.json', $this->surveyJson('anders', 'email'), 'b');
            $this->resources->save(Res::KIND_THEME, 'survey_theme.json', '{"themeName":"x"}', 'b'); // identical to the source
        });

        $r = $this->service()->copy($this->tenantA, $this->tenantB, null, true, S::ROLE_PLATFORM, 'root');

        $this->assertSame(Copy::STATUS_OVERWRITTEN, $r['forms']['bs']['status']);
        $this->assertSame('2026-01-v2', $this->targetConfig('bs')['version']);
        $byKind = array_column($r['forms']['bs']['resources'], 'status', 'kind');
        $this->assertSame(Copy::RESOURCE_OVERWRITTEN, $byKind['survey']);
        $this->assertSame(Copy::RESOURCE_UNCHANGED, $byKind['theme']);
        $this->inTarget(function (): void {
            $notes = array_column($this->revisions->list('bs', Rev::KIND_CONFIG), 'note');
            $this->assertContains('Stand vor dem Kopieren', $notes);
        });
    }

    public function testExistingDifferentSurveyOfTheTargetIsKeptWithoutOverwrite(): void
    {
        $this->seedSource();
        $this->inTarget(fn () => $this->resources->save(Res::KIND_SURVEY, 'bs.json', $this->surveyJson('eigene', 'email'), 'b'));

        $r = $this->service()->copy($this->tenantA, $this->tenantB, null, false, S::ROLE_PLATFORM, 'root');

        $this->assertSame(Copy::STATUS_COPIED, $r['forms']['bs']['status']);
        $this->assertSame(Copy::RESOURCE_KEPT, array_column($r['forms']['bs']['resources'], 'status', 'kind')['survey']);
        $this->inTarget(fn () => $this->assertStringContainsString('eigene', $this->resources->find(Res::KIND_SURVEY, 'bs.json')['content']));
    }

    public function testSubsetAndUnknownKeys(): void
    {
        $this->configs->insert('a', ['form' => 'a.json', 'theme' => 't.json']);
        $this->configs->insert('b', ['form' => 'b.json', 'theme' => 't.json']);

        $r = $this->service()->copy($this->tenantA, $this->tenantB, ['a', 'gibtsnicht', '../x'], false, S::ROLE_PLATFORM, 'root');

        $this->assertSame(Copy::STATUS_COPIED, $r['forms']['a']['status']);
        $this->assertSame(Copy::STATUS_MISSING, $r['forms']['gibtsnicht']['status']);
        $this->assertSame(Copy::STATUS_MISSING, $r['forms']['../x']['status']);
        $this->assertArrayNotHasKey('b', $r['forms']);
        $this->inTarget(fn () => $this->assertSame(['a'], $this->configs->listKeys()));
    }

    public function testSharedThemeIsCopiedOnceAndReportedForEveryForm(): void
    {
        $this->configs->insert('a', ['form' => 'a.json', 'theme' => 'survey_theme.json']);
        $this->configs->insert('b', ['form' => 'b.json', 'theme' => 'survey_theme.json']);
        $this->resources->save(Res::KIND_THEME, 'survey_theme.json', '{"themeName":"x"}', 'a');

        $r = $this->service()->copy($this->tenantA, $this->tenantB, null, false, S::ROLE_PLATFORM, 'root');

        foreach (['a', 'b'] as $key) {
            $this->assertSame(Copy::RESOURCE_COPIED, array_column($r['forms'][$key]['resources'], 'status', 'kind')['theme'], $key);
        }
        $this->assertSame(1, $this->countRows('form_resources', $this->tenantB));
    }

    public function testInvalidSourceDataIsReportedAndSkippedWhileTheOtherFormsAreCopied(): void
    {
        $this->configs->insert('gut', ['form' => 'gut.json', 'theme' => 't.json']);
        $this->configs->insert('schlecht', ['form' => 'schlecht.json', 'theme' => 't.json']);
        // Content that today's rules reject (e.g. stored before the rules existed / by SQL):
        $this->resources->save(Res::KIND_SURVEY, 'schlecht.json', '{"pages":[{"elements":[{"type":"html","name":"h","html":"<script>alert(1)</script>"}]}]}', 'a');

        $r = $this->service()->copy($this->tenantA, $this->tenantB, null, false, S::ROLE_PLATFORM, 'root');

        $this->assertSame(Copy::STATUS_COPIED, $r['forms']['gut']['status']);
        $this->assertSame(Copy::STATUS_INVALID, $r['forms']['schlecht']['status']);
        $this->assertNotEmpty($r['forms']['schlecht']['errors']);
        $this->inTarget(function (): void {
            $this->assertSame(['gut'], $this->configs->listKeys());
            $this->assertNull($this->resources->find(Res::KIND_SURVEY, 'schlecht.json'));
        });
    }

    public function testContactDataInTheCopiedSurveyIsReported(): void
    {
        $this->configs->insert('bs', ['form' => 'bs.json', 'theme' => 't.json']);
        $this->resources->save(Res::KIND_SURVEY, 'bs.json', '{"pages":[{"elements":[{"type":"html","name":"h","html":"<p>Fragen: sekretariat@schule-a.de</p>"},{"type":"text","name":"email"}]}]}', 'a');

        $r = $this->service()->copy($this->tenantA, $this->tenantB, null, false, S::ROLE_PLATFORM, 'root');

        $this->assertCount(1, $r['forms']['bs']['warnings']);
        $this->assertStringContainsString('sekretariat@schule-a.de', $r['forms']['bs']['warnings'][0]['message']);
        $this->assertStringContainsString('pages[0].elements[0].html', $r['forms']['bs']['warnings'][0]['path']);
    }

    public function testEverythingOrNothing(): void
    {
        $this->configs->insert('eins', ['form' => 'eins.json', 'theme' => 't.json']);
        $this->configs->insert('zwei', ['form' => 'zwei.json', 'theme' => 't.json']);
        $this->resources->save(Res::KIND_SURVEY, 'eins.json', $this->surveyJson('a', 'email'), 'a');

        $failing = new class($this->db) extends FormConfigRepository {
            public function insert(string $formKey, array $config): string
            {
                if ($formKey === 'zwei') {
                    throw new \RuntimeException('disk full');
                }
                return parent::insert($formKey, $config);
            }
        };

        try {
            $this->service($failing)->copy($this->tenantA, $this->tenantB, null, false, S::ROLE_PLATFORM, 'root');
            $this->fail('expected RuntimeException');
        } catch (\RuntimeException $e) {
            $this->assertSame('disk full', $e->getMessage());
        }

        $this->assertSame(0, $this->countRows('form_configs', $this->tenantB), 'form "eins" must not be there');
        $this->assertSame(0, $this->countRows('form_resources', $this->tenantB));
        $this->assertSame(0, $this->countRows('form_revisions', $this->tenantB));
        $this->assertSame($this->tenantA, TenantContext::getTenantId());
        $this->assertSame([], $this->audit, 'no "copied" events for a rolled back copy');
    }

    public function testTenantAdminsMayNotCopyAndItIsAudited(): void
    {
        $this->seedSource();

        try {
            $this->service()->copy($this->tenantA, $this->tenantB, null, false, S::ROLE_TENANT, 'schul-admin');
            $this->fail('expected AccessDeniedException');
        } catch (AccessDeniedException) {
            $this->assertSame('form_copy_denied', $this->audit[0][0]);
            $this->assertSame(0, $this->countRows('form_configs', $this->tenantB));
        }
    }

    /** @return array<string,array{0:int|string,1:int|string}> */
    public static function badTenants(): array
    {
        return ['same tenant' => ['A', 'A'], 'unknown source' => [2147483000, 'B'], 'unknown target' => ['A', 2147483000]];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('badTenants')]
    public function testSameOrUnknownTenantsAreRejected(int|string $from, int|string $to): void
    {
        $map = ['A' => $this->tenantA, 'B' => $this->tenantB];
        $this->expectException(\InvalidArgumentException::class);
        $this->service()->copy($map[$from] ?? $from, $map[$to] ?? $to, null, false, S::ROLE_PLATFORM, 'root');
    }

    public function testInactiveTenantsAreRejected(): void
    {
        $this->db->query("UPDATE tenants SET active = 0 WHERE id = {$this->tenantB}");
        $this->expectException(\InvalidArgumentException::class);
        $this->service()->copy($this->tenantA, $this->tenantB, null, false, S::ROLE_PLATFORM, 'root');
    }

    public function testDryRunReportsEverythingAndWritesNothing(): void
    {
        $this->seedSource();
        $this->inTarget(fn () => $this->configs->insert('vorhanden', ['form' => 'v.json', 'theme' => 't.json']));
        $this->configs->insert('vorhanden', ['form' => 'v.json', 'theme' => 't.json']);

        $r = $this->service()->copy($this->tenantA, $this->tenantB, null, false, S::ROLE_PLATFORM, 'root', dryRun: true);

        $this->assertTrue($r['dry_run']);
        $this->assertSame(Copy::STATUS_COPIED, $r['forms']['bs']['status']);
        $this->assertSame(Copy::STATUS_SKIPPED, $r['forms']['vorhanden']['status']);
        $this->inTarget(function (): void {
            $this->assertSame(['vorhanden'], $this->configs->listKeys());
            $this->assertSame([], $this->resources->listNames(Res::KIND_SURVEY));
            $this->assertSame([], $this->revisions->list('bs'));
        });
        $this->assertSame([], $this->audit);
    }

    public function testAuditHasOneEventPerCopiedFormWithoutContent(): void
    {
        $this->seedSource();
        $this->service()->copy($this->tenantA, $this->tenantB, null, false, S::ROLE_PLATFORM, 'root');

        $this->assertCount(1, $this->audit);
        [$event, $form, $details] = $this->audit[0];
        $this->assertSame('form_copied', $event);
        $this->assertSame('bs', $form);
        $this->assertSame($this->tenantA, $details['from_tenant']);
        $this->assertSame($this->tenantB, $details['to_tenant']);
        $this->assertStringNotContainsString('sekretariat@schule-a.de', json_encode($this->audit));
    }

    public function testASecondRunCopiesNothingNew(): void
    {
        $this->seedSource();
        $this->service()->copy($this->tenantA, $this->tenantB, null, false, S::ROLE_PLATFORM, 'root');

        $again = $this->service()->copy($this->tenantA, $this->tenantB, null, false, S::ROLE_PLATFORM, 'root');

        $this->assertSame(Copy::STATUS_SKIPPED, $again['forms']['bs']['status']);
        $this->assertSame(1, $this->countRows('form_configs', $this->tenantB));
    }
}
