<?php
declare(strict_types=1);

namespace Tests\Integration\Forms;

use App\Repositories\FormResourceRepository as Res;
use App\Repositories\FormRevisionRepository as Rev;
use App\Services\SurveyImportService as Import;

/**
 * @group integration
 */
class SurveyImportServiceTest extends FormEditorTestCase
{
    private Import $import;
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->import = new Import($this->resources, $this->configs, $this->revisions);
        $this->dir = sys_get_temp_dir() . '/ondisos-import-' . bin2hex(random_bytes(4));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->dir);
        parent::tearDown();
    }

    public function testRealProjectSurveysAndThemeImportCleanly(): void
    {
        $this->configs->insert('bs', ['form' => 'bs.json', 'theme' => 'survey_theme.json']);
        $outcome = $this->import->importDirectory(__DIR__ . '/../../../../frontend/surveys', 'cli');

        $this->assertNotEmpty($outcome);
        foreach ($outcome as $file => $r) {
            $this->assertSame(Import::STATUS_IMPORTED, $r['status'], $file . ': ' . json_encode($r['validation']->errors()));
        }
        $this->assertNotNull($this->resources->find(Res::KIND_THEME, 'survey_theme.json'), 'theme imported as theme');
        $this->assertNull($this->resources->find(Res::KIND_SURVEY, 'survey_theme.json'));
        $this->assertNotNull($this->resources->find(Res::KIND_SURVEY, 'bs.json'));
    }

    public function testImportIsIdempotentAndDoesNotOverwriteByDefault(): void
    {
        $this->assertSame(Import::STATUS_IMPORTED, $this->import->importResource(Res::KIND_SURVEY, 'a.json', $this->surveyJson('x'), 'cli')['status']);
        $this->assertSame(Import::STATUS_UNCHANGED, $this->import->importResource(Res::KIND_SURVEY, 'a.json', $this->surveyJson('x'), 'cli')['status']);
        $this->assertSame(Import::STATUS_SKIPPED, $this->import->importResource(Res::KIND_SURVEY, 'a.json', $this->surveyJson('y'), 'cli')['status']);
        $this->assertStringContainsString('"x"', $this->resources->find(Res::KIND_SURVEY, 'a.json')['content']);
    }

    public function testOverwriteReplacesAndKeepsPreviousStateInHistoryOfUsingForms(): void
    {
        $this->configs->insert('bs', ['form' => 'a.json', 'theme' => 't.json']);
        $this->configs->insert('zweites', ['form' => 'a.json', 'theme' => 't.json']);
        $this->configs->insert('anderes', ['form' => 'b.json', 'theme' => 't.json']);
        $this->import->importResource(Res::KIND_SURVEY, 'a.json', $this->surveyJson('alt'), 'cli');

        $r = $this->import->importResource(Res::KIND_SURVEY, 'a.json', $this->surveyJson('neu'), 'cli', overwrite: true);

        $this->assertSame(Import::STATUS_IMPORTED, $r['status']);
        $this->assertStringContainsString('neu', $this->resources->find(Res::KIND_SURVEY, 'a.json')['content']);
        foreach (['bs', 'zweites'] as $form) {
            $revs = $this->revisions->list($form, Rev::KIND_SURVEY);
            $this->assertCount(1, $revs, $form);
            $this->assertStringContainsString('alt', $this->revisions->find($revs[0]['id'])['content']);
        }
        $this->assertCount(0, $this->revisions->list('anderes'));
    }

    public function testInvalidContentIsRejectedAndNotStored(): void
    {
        $bad = '{"pages":[{"elements":[{"type":"html","name":"h","html":"<img src=x onerror=alert(1)>"}]}]}';
        $r = $this->import->importResource(Res::KIND_SURVEY, 'bad.json', $bad, 'cli');

        $this->assertSame(Import::STATUS_INVALID, $r['status']);
        $this->assertFalse($r['validation']->isValid());
        $this->assertNull($this->resources->find(Res::KIND_SURVEY, 'bad.json'));
    }

    public function testInvalidThemeIsRejected(): void
    {
        $r = $this->import->importResource(Res::KIND_THEME, 't.json', '{"cssVariables":{"--x":"<script>"}}', 'cli');
        $this->assertSame(Import::STATUS_INVALID, $r['status']);
    }

    public function testBadFileNamesAreRejected(): void
    {
        foreach (['../x.json', 'x.php', 'X.json', "x.json\n"] as $name) {
            $r = $this->import->importResource(Res::KIND_SURVEY, $name, $this->surveyJson('a'), 'cli');
            $this->assertSame(Import::STATUS_INVALID, $r['status'], $name);
        }
    }

    public function testDirectoryImportSkipsSymlinksAndReportsBrokenFilesWithoutAbortingTheRest(): void
    {
        file_put_contents($this->dir . '/gut.json', $this->surveyJson('a'));
        file_put_contents($this->dir . '/kaputt.json', '{nope');
        file_put_contents($this->dir . '/Gross.json', $this->surveyJson('a'));
        file_put_contents($this->dir . '/secret.txt', 'not json file');
        symlink('/etc/hosts', $this->dir . '/link.json');

        $out = $this->import->importDirectory($this->dir, 'cli');

        $this->assertSame(Import::STATUS_IMPORTED, $out['gut.json']['status']);
        $this->assertSame(Import::STATUS_INVALID, $out['kaputt.json']['status']);
        $this->assertSame(Import::STATUS_INVALID, $out['Gross.json']['status']);
        $this->assertArrayNotHasKey('link.json', $out);
        $this->assertArrayNotHasKey('secret.txt', $out);
    }

    public function testImportIsScopedToTheCurrentTenant(): void
    {
        $this->import->importResource(Res::KIND_SURVEY, 'a.json', $this->surveyJson('nur-a'), 'cli');
        $this->asTenant($this->tenantB);
        $this->assertNull($this->resources->find(Res::KIND_SURVEY, 'a.json'));
        $this->assertSame(Import::STATUS_IMPORTED, $this->import->importResource(Res::KIND_SURVEY, 'a.json', $this->surveyJson('nur-b'), 'cli')['status']);
        $this->asTenant($this->tenantA);
        $this->assertStringContainsString('nur-a', $this->resources->find(Res::KIND_SURVEY, 'a.json')['content']);
    }
}
