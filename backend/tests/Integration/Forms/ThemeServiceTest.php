<?php
declare(strict_types=1);

namespace Tests\Integration\Forms;

use App\Forms\ConflictException;
use App\Forms\NotFoundException;
use App\Repositories\FormResourceRepository as Res;
use App\Repositories\FormRevisionRepository as Rev;
use App\Services\ThemeService;

/**
 * Theme editor (3.2.0b) against a real database: list, load, publish with locking, history, restore, isolation.
 *
 * @group integration
 */
class ThemeServiceTest extends FormEditorTestCase
{
    private ThemeService $service;

    /** @var list<array{0:string,1:string,2:array<string,mixed>}> */
    private array $auditEvents = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->auditEvents = [];
        $this->service = new ThemeService(
            $this->db,
            $this->configs,
            $this->resources,
            $this->revisions,
            audit: function (string $event, string $name, array $details): void {
                $this->auditEvents[] = [$event, $name, $details];
            },
        );
    }

    private function addForm(string $key, string $theme = 'survey_theme.json'): void
    {
        $this->configs->insert($key, ['form' => $key . '.json', 'theme' => $theme, 'version' => '1.0.0', 'db' => true]);
    }

    private function theme(string $color): string
    {
        return json_encode(['themeName' => 'default', 'cssVariables' => ['--sjs2-color-project-brand-600' => $color]], JSON_THROW_ON_ERROR);
    }

    /** @return list<string> notes of the theme's revisions, oldest first */
    private function notes(string $name = 'survey_theme.json'): array
    {
        return array_map(static fn (array $r): string => (string)$r['note'], array_reverse($this->service->revisions($name)));
    }

    // ---- list and load ----------------------------------------------------------------------

    public function testThemesListsStoredAndReferencedNamesWithTheirForms(): void
    {
        $this->addForm('vabo');
        $this->addForm('bs');
        $this->addForm('zq', 'dunkel.json');
        $this->resources->save(Res::KIND_THEME, 'alt.json', $this->theme('#000'), 'import');
        $this->resources->save(Res::KIND_THEME, 'survey_theme.json', $this->theme('#111'), 'import');

        $themes = array_column($this->service->themes(), null, 'name');

        $this->assertSame(['alt.json', 'dunkel.json', 'survey_theme.json'], array_keys($themes));
        $this->assertSame(['bs', 'vabo'], $themes['survey_theme.json']['forms']);
        $this->assertTrue($themes['survey_theme.json']['exists']);
        $this->assertFalse($themes['dunkel.json']['exists'], 'named by a form, not stored yet');
        $this->assertNull($themes['dunkel.json']['sha256']);
        $this->assertSame([], $themes['alt.json']['forms'], 'stored, used by no form');
    }

    public function testLoadOfAThemeThatIsNotStoredYetStartsWithTheDefault(): void
    {
        $this->addForm('bs');

        $loaded = $this->service->load('survey_theme.json');

        $this->assertFalse($loaded['exists']);
        $this->assertNull($loaded['sha256']);
        $this->assertSame($this->service->defaultTheme(), $loaded['content']);
        $this->assertSame(['bs'], $loaded['forms']);
    }

    public function testLoadReturnsTheStoredTheme(): void
    {
        $sha = $this->resources->save(Res::KIND_THEME, 'survey_theme.json', $this->theme('#123456'), 'import');

        $loaded = $this->service->load('survey_theme.json');

        $this->assertTrue($loaded['exists']);
        $this->assertSame($sha, $loaded['sha256']);
        $this->assertSame($this->theme('#123456'), $loaded['content']);
    }

    public function testUnknownOrInvalidNamesAreNotFound(): void
    {
        foreach (['frei_erfunden.json', '../x.json', 'kein-json'] as $name) {
            try {
                $this->service->load($name);
                $this->fail("expected NotFoundException for {$name}");
            } catch (NotFoundException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testPublishOfAnUnknownNameIsNotFoundAndWritesNothing(): void
    {
        $this->expectException(NotFoundException::class);
        try {
            $this->service->publish('frei_erfunden.json', $this->theme('#000'), null, 'admin');
        } finally {
            $this->assertSame(0, $this->countRows('form_resources', $this->tenantA));
        }
    }

    // ---- publish -----------------------------------------------------------------------------

    public function testPublishCreatesAThemeThatAFormNames(): void
    {
        $this->addForm('bs');

        $result = $this->service->publish('survey_theme.json', $this->theme('#c00'), null, 'admin-a');

        $this->assertTrue($result->ok());
        $this->assertSame($this->theme('#c00'), $this->resources->find(Res::KIND_THEME, 'survey_theme.json')['content']);
        $this->assertSame(['Veröffentlicht'], $this->notes());
        $this->assertSame('theme_published', $this->auditEvents[0][0]);
        $this->assertSame('survey_theme.json', $this->auditEvents[0][1]);
        $this->assertSame($result->revisionId, $this->auditEvents[0][2]['revision']);
    }

    public function testPublishKeepsTheImportedStateInTheHistoryFirst(): void
    {
        $sha = $this->resources->save(Res::KIND_THEME, 'survey_theme.json', $this->theme('#111'), 'import');

        $result = $this->service->publish('survey_theme.json', $this->theme('#222'), $sha, 'admin-a', 'Neue Farbe');

        $this->assertTrue($result->ok());
        $this->assertSame(['Stand vor der Änderung', 'Neue Farbe'], $this->notes());
        $this->assertSame(hash('sha256', $this->theme('#222')), $result->sha256);
    }

    public function testPublishWithAStaleVersionIsAConflictAndChangesNothing(): void
    {
        $this->resources->save(Res::KIND_THEME, 'survey_theme.json', $this->theme('#111'), 'import');
        $stale = hash('sha256', 'something else');

        try {
            $this->service->publish('survey_theme.json', $this->theme('#222'), $stale, 'admin-a');
            $this->fail('expected ConflictException');
        } catch (ConflictException) {
            $this->assertSame($this->theme('#111'), $this->resources->find(Res::KIND_THEME, 'survey_theme.json')['content']);
            $this->assertSame([], $this->notes());
        }
    }

    public function testPublishAsNewWhileSomeoneElseCreatedItIsAConflict(): void
    {
        $this->addForm('bs');
        $this->service->publish('survey_theme.json', $this->theme('#111'), null, 'admin-a');

        $this->expectException(ConflictException::class);
        $this->service->publish('survey_theme.json', $this->theme('#222'), null, 'admin-b');
    }

    public function testInvalidThemeIsRejectedWithoutWritingAndAuditedWithoutContent(): void
    {
        $this->addForm('bs');
        $evil = json_encode(['cssVariables' => ['--x' => '<script>alert(1)</script>']], JSON_THROW_ON_ERROR);

        $result = $this->service->publish('survey_theme.json', $evil, null, 'admin-a');

        $this->assertFalse($result->ok());
        $this->assertNull($this->resources->find(Res::KIND_THEME, 'survey_theme.json'));
        $this->assertSame(0, $this->countRows('form_revisions', $this->tenantA));
        $this->assertSame('theme_rejected', $this->auditEvents[0][0]);
        $this->assertStringNotContainsString('script', json_encode($this->auditEvents, JSON_THROW_ON_ERROR));
    }

    public function testPublishingTheSameContentAgainAddsNoRevision(): void
    {
        $this->addForm('bs');
        $first = $this->service->publish('survey_theme.json', $this->theme('#111'), null, 'admin-a');

        $again = $this->service->publish('survey_theme.json', "  {$this->theme('#111')}\n", $first->sha256, 'admin-a');

        $this->assertTrue($again->ok());
        $this->assertNull($again->revisionId);
        $this->assertSame(['Veröffentlicht'], $this->notes());
    }

    public function testCheckReportsErrorsAndStoresNothing(): void
    {
        $this->assertFalse($this->service->check('[1, 2]')->isValid());
        $this->assertTrue($this->service->check($this->theme('#fff'))->isValid());
        $this->assertSame(0, $this->countRows('form_resources', $this->tenantA));
    }

    // ---- history and restore -------------------------------------------------------------------

    public function testRestoreMakesAnEarlierRevisionLiveAgain(): void
    {
        $this->addForm('bs');
        $first  = $this->service->publish('survey_theme.json', $this->theme('#111'), null, 'admin-a');
        $second = $this->service->publish('survey_theme.json', $this->theme('#222'), $first->sha256, 'admin-a');

        $result = $this->service->restoreRevision('survey_theme.json', (int)$first->revisionId, 'admin-b');

        $this->assertTrue($result->ok());
        $this->assertSame($this->theme('#111'), $this->resources->find(Res::KIND_THEME, 'survey_theme.json')['content']);
        $this->assertSame(['Veröffentlicht', 'Veröffentlicht', "Wiederhergestellt aus Revision #{$first->revisionId}"], $this->notes());
        $this->assertSame('theme_rolled_back', end($this->auditEvents)[0]);
        $this->assertNotNull($second->revisionId);
    }

    public function testRestoreRefusesRevisionsOfOtherThemesAndOfForms(): void
    {
        $this->addForm('bs');
        $this->addForm('zq', 'anders.json');
        $other  = $this->service->publish('anders.json', $this->theme('#333'), null, 'admin-a');
        $survey = $this->revisions->add('bs', Rev::KIND_SURVEY, 'bs.json', $this->surveyJson(), 'x', 'admin-a');

        foreach ([(int)$other->revisionId, $survey, 999999999] as $id) {
            try {
                $this->service->restoreRevision('survey_theme.json', $id, 'admin-a');
                $this->fail("expected NotFoundException for revision {$id}");
            } catch (NotFoundException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testPruneKeepsTheNewestRevisionsPerThemeName(): void
    {
        foreach (['a.json', 'b.json'] as $name) {
            foreach (['#1', '#2', '#3'] as $note) {
                $this->revisions->add(Rev::THEME_KEY, Rev::KIND_THEME, $name, $this->theme($note), $note, 'u');
            }
        }

        $this->revisions->pruneOldest(Rev::THEME_KEY, Rev::KIND_THEME, 1, 'a.json');

        $this->assertSame(['#3'], array_column($this->revisions->list(Rev::THEME_KEY, Rev::KIND_THEME, 50, 'a.json'), 'note'));
        $this->assertCount(3, $this->revisions->list(Rev::THEME_KEY, Rev::KIND_THEME, 50, 'b.json'));
    }

    // ---- tenants -----------------------------------------------------------------------------

    public function testThemesAndRevisionsOfAnotherTenantAreInvisible(): void
    {
        $this->addForm('bs');
        $published = $this->service->publish('survey_theme.json', $this->theme('#111'), null, 'admin-a');

        $this->asTenant($this->tenantB);
        $this->addForm('bs');

        $loaded = $this->service->load('survey_theme.json');
        $this->assertFalse($loaded['exists'], 'tenant B has its own, not yet stored theme');
        $this->assertSame([], $this->service->revisions('survey_theme.json'));

        $this->expectException(NotFoundException::class);
        $this->service->restoreRevision('survey_theme.json', (int)$published->revisionId, 'admin-b');
    }
}
