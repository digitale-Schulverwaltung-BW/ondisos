<?php
declare(strict_types=1);

namespace Tests\Integration\Forms;

use App\Cli\ImportSurveysCommand;
use App\Config\TenantContext;
use App\Repositories\FormResourceRepository as Res;
use App\Repositories\TenantRepository;

/**
 * The import-surveys.php command (everything except argv/env/db bootstrapping of the script itself).
 *
 * @group integration
 */
class ImportSurveysCommandTest extends FormEditorTestCase
{
    private string $dir;
    private string $slugA;
    private string $slugB;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . '/ondisos-cmd-' . bin2hex(random_bytes(4));
        mkdir($this->dir);
        $tenants = new TenantRepository($this->db);
        $this->slugA = $tenants->findById($this->tenantA)['slug'];
        $this->slugB = $tenants->findById($this->tenantB)['slug'];
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->dir);
        parent::tearDown();
    }

    /**
     * @param list<string> $args
     * @return array{code:int, out:string, err:string}
     */
    private function runCommand(array $args): array
    {
        $out = $err = '';
        $cmd = new ImportSurveysCommand(new TenantRepository($this->db), $this->configs, $this->resources, $this->revisions);
        $code = $cmd->run($args, function (string $s) use (&$out): void { $out .= $s; }, function (string $s) use (&$err): void { $err .= $s; });
        TenantContext::initialize($this->tenantA); // the command switched the context; restore for assertions
        return ['code' => $code, 'out' => $out, 'err' => $err];
    }

    private function inTenant(int $id, callable $fn): mixed
    {
        TenantContext::initialize($id);
        try {
            return $fn();
        } finally {
            TenantContext::initialize($this->tenantA);
        }
    }

    public function testImportsRealSurveysForTheChosenTenantOnly(): void
    {
        $r = $this->runCommand(['--tenant=' . $this->slugB, __DIR__ . '/../../../../frontend/surveys']);

        $this->assertSame(0, $r['code'], $r['out'] . $r['err']);
        $this->assertStringContainsString('bs.json', $r['out']);
        $this->assertStringContainsString('imported', $r['out']);
        $this->assertNotNull($this->inTenant($this->tenantB, fn () => $this->resources->find(Res::KIND_SURVEY, 'bs.json')));
        $this->assertNull($this->resources->find(Res::KIND_SURVEY, 'bs.json'), 'tenant A must stay untouched');
    }

    public function testThemeIsRecognizedByContentEvenWithoutAConfig(): void
    {
        file_put_contents($this->dir . '/mein_design.json', '{"themeName":"x","cssVariables":{"--a":"1"}}');
        file_put_contents($this->dir . '/formular.json', $this->surveyJson('a', 'email'));

        $r = $this->runCommand(['--tenant=' . $this->slugA, $this->dir]);

        $this->assertSame(0, $r['code'], $r['out']);
        $this->assertNotNull($this->resources->find(Res::KIND_THEME, 'mein_design.json'));
        $this->assertNull($this->resources->find(Res::KIND_SURVEY, 'mein_design.json'));
        $this->assertNotNull($this->resources->find(Res::KIND_SURVEY, 'formular.json'));
    }

    public function testDryRunWritesNothingButReportsWhatWouldHappen(): void
    {
        file_put_contents($this->dir . '/a.json', $this->surveyJson('a', 'email'));

        $r = $this->runCommand(['--dry-run', '--tenant=' . $this->slugA, $this->dir]);

        $this->assertSame(0, $r['code']);
        $this->assertStringContainsString('[dry run]', $r['out']);
        $this->assertStringContainsString('would be imported', $r['out']);
        $this->assertNull($this->resources->find(Res::KIND_SURVEY, 'a.json'));
    }

    public function testSecondRunIsUnchangedAndChangedFilesAreSkippedUntilOverwrite(): void
    {
        file_put_contents($this->dir . '/a.json', $this->surveyJson('v1', 'email'));
        $this->runCommand(['--tenant=' . $this->slugA, $this->dir]);

        $again = $this->runCommand(['--tenant=' . $this->slugA, $this->dir]);
        $this->assertStringContainsString('unchanged', $again['out']);

        file_put_contents($this->dir . '/a.json', $this->surveyJson('v2', 'email'));
        $skipped = $this->runCommand(['--tenant=' . $this->slugA, $this->dir]);
        $this->assertStringContainsString('SKIPPED', $skipped['out']);
        $this->assertStringContainsString('--overwrite', $skipped['out']);
        $this->assertStringContainsString('v1', $this->resources->find(Res::KIND_SURVEY, 'a.json')['content']);

        $over = $this->runCommand(['--overwrite', '--tenant=' . $this->slugA, $this->dir]);
        $this->assertSame(0, $over['code']);
        $this->assertStringContainsString('v2', $this->resources->find(Res::KIND_SURVEY, 'a.json')['content']);
    }

    public function testInvalidFilesAreReportedWithReasonsAndGiveExitCode1ButOthersStillImport(): void
    {
        file_put_contents($this->dir . '/gut.json', $this->surveyJson('a', 'email'));
        file_put_contents($this->dir . '/boese.json', '{"pages":[{"elements":[{"type":"html","name":"h","html":"<script>alert(1)</script>"}]}]}');
        file_put_contents($this->dir . '/kaputt.json', '{nope');

        $r = $this->runCommand(['--tenant=' . $this->slugA, $this->dir]);

        $this->assertSame(1, $r['code']);
        $this->assertStringContainsString('INVALID', $r['out']);
        $this->assertStringContainsString('<script>', $r['out']);
        $this->assertStringContainsString('pages[0].elements[0].html', $r['out'], 'tells where the problem is');
        $this->assertNotNull($this->resources->find(Res::KIND_SURVEY, 'gut.json'));
        $this->assertNull($this->resources->find(Res::KIND_SURVEY, 'boese.json'));
    }

    public function testWarningsAreShown(): void
    {
        file_put_contents($this->dir . '/w.json', '{"pages":[{"elements":[{"type":"text","name":"a","visibleIf":"evil({b}) = 1"}]}]}');
        $r = $this->runCommand(['--tenant=' . $this->slugA, $this->dir]);
        $this->assertSame(0, $r['code']);
        $this->assertStringContainsString('warning:', $r['out']);
        $this->assertStringContainsString('evil()', $r['out']);
    }

    public function testDefaultTenantIsTheOneWithSlugDefault(): void
    {
        file_put_contents($this->dir . '/a.json', $this->surveyJson('a', 'email'));
        $r = $this->runCommand(['--dry-run', $this->dir]);
        $this->assertSame(0, $r['code'], $r['err']);
        $this->assertStringContainsString('(default)', $r['out']);
    }

    /** @return array<string,array{0:list<string>,1:int,2:string}> */
    public static function usageErrors(): array
    {
        return [
            'no directory'    => [[], 2, 'Usage'],
            'two directories' => [['/tmp', '/var'], 2, 'Usage'],
            'typo in option'  => [['--overwite', '/tmp'], 2, 'Unknown option: --overwite'],
            'not a directory' => [['/definitely/not/there'], 2, 'not a directory'],
            'unknown tenant'  => [['--tenant=gibts-nicht', '/tmp'], 2, "unknown tenant 'gibts-nicht'"],
        ];
    }

    /** @param list<string> $args */
    #[\PHPUnit\Framework\Attributes\DataProvider('usageErrors')]
    public function testUsageAndSetupErrors(array $args, int $code, string $message): void
    {
        $r = $this->runCommand($args);
        $this->assertSame($code, $r['code']);
        $this->assertStringContainsString($message, $r['err']);
        $this->assertSame(0, $this->countRows('form_resources', $this->tenantA));
    }

    public function testHelpPrintsUsageAndExitsZero(): void
    {
        $r = $this->runCommand(['--help']);
        $this->assertSame(0, $r['code']);
        $this->assertStringContainsString('--overwrite', $r['out']);
        $this->assertStringContainsString('docker compose cp', $r['out']);
    }

    public function testEmptyDirectoryIsNotAnError(): void
    {
        $r = $this->runCommand(['--tenant=' . $this->slugA, $this->dir]);
        $this->assertSame(0, $r['code']);
        $this->assertStringContainsString('No *.json files', $r['out']);
    }
}
