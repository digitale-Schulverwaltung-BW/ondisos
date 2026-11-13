<?php
declare(strict_types=1);

namespace Tests\Integration\Forms;

use App\Controllers\ThemeEditorController;
use App\Repositories\FormResourceRepository as Res;
use App\Services\ThemeService;

/**
 * The theme editor pages (3.2.0b AP2) against a real database: list, load with history, check with line numbers,
 * publish with locking, restore, tenant separation.
 *
 * @group integration
 */
class ThemeEditorControllerTest extends FormEditorTestCase
{
    private const NAME = 'survey_theme.json';

    private function editor(string $user = 'anna'): ThemeEditorController
    {
        return new ThemeEditorController(
            new ThemeService($this->db, $this->configs, $this->resources, $this->revisions, audit: static function (): void {}),
            $user,
        );
    }

    private function addForm(string $key, string $theme = self::NAME): void
    {
        $this->configs->insert($key, ['form' => $key . '.json', 'theme' => $theme, 'version' => '1.0.0', 'db' => true]);
    }

    private function theme(string $color): string
    {
        return json_encode(['themeName' => 'default', 'cssVariables' => ['--sjs2-color-project-brand-600' => $color]], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
    }

    public function testListShowsNamedButMissingThemesWithTheirForms(): void
    {
        $this->addForm('bs');
        $this->addForm('vabo');

        $themes = $this->editor()->listThemes();

        $this->assertCount(1, $themes);
        $this->assertSame(self::NAME, $themes[0]['name']);
        $this->assertFalse($themes[0]['exists']);
        $this->assertSame(['bs', 'vabo'], $themes[0]['forms']);
    }

    public function testLoadOfAnUnknownThemeIsNull(): void
    {
        $this->assertNull($this->editor()->load('frei_erfunden.json'));
        $this->assertNull($this->editor()->check('frei_erfunden.json', $this->theme('#000')));
    }

    public function testCheckReportsSyntaxErrorsWithLineAndColumn(): void
    {
        $this->addForm('bs');

        $r = $this->editor()->check(self::NAME, "{\n  \"themeName\": \"x\"\n  \"cssVariables\": {}\n}");

        $this->assertFalse($r['valid']);
        $this->assertSame(3, $r['errors'][0]['line']);
        $this->assertNotNull($r['errors'][0]['column']);
        $this->assertNull($r['diff']);
    }

    public function testCheckPointsForbiddenContentAndExternalResourcesAtTheirLine(): void
    {
        $this->addForm('bs');
        $json = "{\n  \"backgroundImage\": \"https://cdn.example.org/bg.png\",\n  \"cssVariables\": {\n    \"--x\": \"javascript:alert(1)\"\n  }\n}";

        $r = $this->editor()->check(self::NAME, $json);

        $this->assertFalse($r['valid']);
        $this->assertSame('cssVariables.--x', $r['errors'][0]['path']);
        $this->assertSame(4, $r['errors'][0]['line']);
        $this->assertSame('backgroundImage', $r['warnings'][0]['path']);
        $this->assertSame(2, $r['warnings'][0]['line']);
    }

    public function testCheckOfAFirstThemeNamesTheFormsAndHasNoDiff(): void
    {
        $this->addForm('bs');

        $r = $this->editor()->check(self::NAME, $this->theme('#c00'));

        $this->assertTrue($r['valid']);
        $this->assertTrue($r['first_publish']);
        $this->assertNull($r['diff']);
        $this->assertSame(['bs'], $r['forms']);
    }

    public function testCheckAgainstTheLiveThemeShowsADiffAndStoresNothing(): void
    {
        $this->resources->save(Res::KIND_THEME, self::NAME, $this->theme('#111111'), 'import');

        $r = $this->editor()->check(self::NAME, $this->theme('#222222'));

        $this->assertTrue($r['valid']);
        $this->assertFalse($r['diff']['identical']);
        $this->assertSame(1, $r['diff']['added']);
        $this->assertSame($this->theme('#111111'), $this->resources->find(Res::KIND_THEME, self::NAME)['content']);
    }

    public function testPublishWithTheLoadedShaGoesLiveAndAppearsInTheHistory(): void
    {
        $this->addForm('bs');
        $loaded = $this->editor()->load(self::NAME);

        $out = $this->editor('anna')->publish(self::NAME, $this->theme('#c00'), (string)$loaded['sha256'], '  Schulfarbe  ');

        $this->assertSame('published', $out['status']);
        $view = $this->editor()->load(self::NAME);
        $this->assertTrue($view['exists']);
        $this->assertSame('anna', $view['updated_by']);
        $this->assertSame('Schulfarbe', $view['revisions'][0]['note']);
    }

    public function testPublishOfTheSameContentIsUnchanged(): void
    {
        $sha = $this->resources->save(Res::KIND_THEME, self::NAME, $this->theme('#111111'), 'import');

        $out = $this->editor()->publish(self::NAME, $this->theme('#111111'), $sha, null);

        $this->assertSame('unchanged', $out['status']);
        $this->assertSame([], $this->editor()->load(self::NAME)['revisions']);
    }

    public function testPublishAfterSomebodyElsePublishedIsAConflictWithADiffAgainstTheNewState(): void
    {
        $this->addForm('bs');
        $opened = (string)$this->editor()->load(self::NAME)['sha256']; // '' – not stored yet
        $this->editor('bert')->publish(self::NAME, $this->theme('#00f'), $opened, null);

        $out = $this->editor('anna')->publish(self::NAME, $this->theme('#c00'), $opened, null);

        $this->assertSame('conflict', $out['status']);
        $this->assertFalse($out['report']['diff']['identical'], 'the diff compares with what bert published');
        $this->assertStringContainsString('#00f', $this->resources->find(Res::KIND_THEME, self::NAME)['content']);
    }

    public function testInvalidContentIsNotPublished(): void
    {
        $this->addForm('bs');

        $out = $this->editor()->publish(self::NAME, '{"cssVariables": {"--x": "<script>"}}', '', null);

        $this->assertSame('invalid', $out['status']);
        $this->assertFalse($out['report']['valid']);
        $this->assertNull($this->resources->find(Res::KIND_THEME, self::NAME));
    }

    public function testRestoreBringsBackAnEarlierStateAndAgainIsUnchanged(): void
    {
        $this->addForm('bs');
        $this->editor()->publish(self::NAME, $this->theme('#111111'), '', null);
        $sha = (string)$this->editor()->load(self::NAME)['sha256'];
        $this->editor()->publish(self::NAME, $this->theme('#222222'), $sha, null);
        $first = array_values(array_filter($this->editor()->load(self::NAME)['revisions'], static fn (array $r): bool => $r['sha256'] === $sha))[0];

        $this->assertSame('restored', $this->editor()->restore(self::NAME, (int)$first['id'])['status']);
        $this->assertSame($this->theme('#111111'), $this->resources->find(Res::KIND_THEME, self::NAME)['content']);
        $this->assertSame('unchanged', $this->editor()->restore(self::NAME, (int)$first['id'])['status']);
    }

    public function testRestoreOfARevisionOfAnotherThemeIsNotFound(): void
    {
        $this->addForm('bs');
        $this->addForm('zq', 'dunkel.json');
        $this->editor()->publish('dunkel.json', $this->theme('#000000'), '', null);
        $foreign = (int)$this->editor()->load('dunkel.json')['revisions'][0]['id'];

        $this->assertSame('not_found', $this->editor()->restore(self::NAME, $foreign)['status']);
    }

    public function testATenantAdminSeesOnlyTheThemesOfTheirSchool(): void
    {
        $this->addForm('bs', 'schule_a.json');
        $this->editor()->publish('schule_a.json', $this->theme('#a00'), '', null);

        $this->asTenant($this->tenantB);

        $this->assertSame([], $this->editor()->listThemes());
        $this->assertNull($this->editor()->load('schule_a.json'));
        $this->assertSame('not_found', $this->editor()->publish('schule_a.json', $this->theme('#b00'), '', null)['status']);
    }
}
