<?php
declare(strict_types=1);

namespace Tests\Integration\Forms;

use App\Cli\CopyFormsCommand;
use App\Config\TenantContext;
use App\Repositories\FormResourceRepository as Res;
use App\Repositories\TenantRepository;
use App\Services\FormCopyService;

/**
 * @group integration
 */
class CopyFormsCommandTest extends FormEditorTestCase
{
    private string $slugA;
    private string $slugB;

    protected function setUp(): void
    {
        parent::setUp();
        $t = new TenantRepository($this->db);
        $this->slugA = $t->findById($this->tenantA)['slug'];
        $this->slugB = $t->findById($this->tenantB)['slug'];

        $this->configs->insert('bs', ['form' => 'bs.json', 'theme' => 't.json', 'notify_email' => 'a@schule-a.de', 'pdf' => ['footer_text' => 'Schule A']]);
        $this->configs->insert('info', ['form' => 'info.json', 'theme' => 't.json', 'db' => false, 'notify_email' => 'a@schule-a.de']);
        $this->resources->save(Res::KIND_SURVEY, 'bs.json', $this->surveyJson('a', 'email'), 'u');
    }

    /** @return array{code:int,out:string,err:string} */
    private function runCommand(array $args): array
    {
        $out = $err = '';
        $tenants = new TenantRepository($this->db);
        $cmd = new CopyFormsCommand($tenants, new FormCopyService(
            $this->db, $tenants, $this->configs, $this->resources, $this->revisions,
            audit: static function (): void {},
        ));
        $code = $cmd->run($args, function (string $s) use (&$out): void { $out .= $s; }, function (string $s) use (&$err): void { $err .= $s; });
        TenantContext::initialize($this->tenantA);
        return ['code' => $code, 'out' => $out, 'err' => $err];
    }

    public function testCopiesAndTellsWhatToDoNext(): void
    {
        $r = $this->runCommand(["--from={$this->slugA}", "--to={$this->slugB}"]);

        $this->assertSame(0, $r['code'], $r['out'] . $r['err']);
        $this->assertStringContainsString('bs', $r['out']);
        $this->assertStringContainsString('copied', $r['out']);
        $this->assertStringContainsString('pdf.footer_text', $r['out'], 'texts to check are listed');
        $this->assertStringContainsString('NEXT: enter the recipient', $r['out']);
        $this->assertStringContainsString('info (hidden until set)', $r['out']);
        $this->assertSame(2, $this->countRows('form_configs', $this->tenantB));
    }

    public function testDryRunWritesNothing(): void
    {
        $r = $this->runCommand(["--from={$this->slugA}", "--to={$this->slugB}", '--dry-run']);

        $this->assertSame(0, $r['code']);
        $this->assertStringContainsString('[dry run]', $r['out']);
        $this->assertStringContainsString('would be copied', $r['out']);
        $this->assertSame(0, $this->countRows('form_configs', $this->tenantB));
    }

    public function testFormsOptionAndMissingForm(): void
    {
        $r = $this->runCommand(["--from={$this->slugA}", "--to={$this->slugB}", '--forms=bs,nix']);

        $this->assertSame(0, $r['code']);
        $this->assertStringContainsString('not found in the source', $r['out']);
        $this->assertSame(1, $this->countRows('form_configs', $this->tenantB));
    }

    public function testSecondRunSkipsAndOverwriteReplaces(): void
    {
        $this->runCommand(["--from={$this->slugA}", "--to={$this->slugB}"]);

        $again = $this->runCommand(["--from={$this->slugA}", "--to={$this->slugB}"]);
        $this->assertStringContainsString('skipped', $again['out']);
        $this->assertStringContainsString('--overwrite', $again['out']);

        $over = $this->runCommand(["--from={$this->slugA}", "--to={$this->slugB}", '--overwrite']);
        $this->assertStringContainsString('overwritten', $over['out']);
    }

    public function testInvalidSourceGivesExitCode1(): void
    {
        $this->resources->save(Res::KIND_SURVEY, 'bs.json', '{"pages":[{"elements":[{"type":"html","name":"h","html":"<script>x</script>"}]}]}', 'u');

        $r = $this->runCommand(["--from={$this->slugA}", "--to={$this->slugB}"]);

        $this->assertSame(1, $r['code']);
        $this->assertStringContainsString('INVALID', $r['out']);
        $this->assertSame(1, $this->countRows('form_configs', $this->tenantB), 'the valid form was still copied');
    }

    /** @return array<string,array{0:list<string>,1:string}> */
    public static function usageErrors(): array
    {
        return [
            'no options'     => [[], 'Usage'],
            'only from'      => [['--from=x'], 'Usage'],
            'typo'           => [['--from=a', '--to=b', '--overwite'], 'Unknown option'],
            'positional'     => [['--from=a', '--to=b', 'extra'], 'Usage'],
        ];
    }

    /** @param list<string> $args */
    #[\PHPUnit\Framework\Attributes\DataProvider('usageErrors')]
    public function testUsageErrors(array $args, string $message): void
    {
        $r = $this->runCommand($args);
        $this->assertSame(2, $r['code']);
        $this->assertStringContainsString($message, $r['err']);
    }

    public function testUnknownTenantsAndSameTenant(): void
    {
        $this->assertSame(2, $this->runCommand(["--from=gibts-nicht", "--to={$this->slugB}"])['code']);
        $this->assertSame(2, $this->runCommand(["--from={$this->slugA}", "--to=gibts-nicht"])['code']);
        $same = $this->runCommand(["--from={$this->slugA}", "--to={$this->slugA}"]);
        $this->assertSame(2, $same['code']);
        $this->assertStringContainsString('derselbe Tenant', $same['err']);
    }

    public function testHelp(): void
    {
        $r = $this->runCommand(['--help']);
        $this->assertSame(0, $r['code']);
        $this->assertStringContainsString('NOT copied', $r['out']);
    }
}
