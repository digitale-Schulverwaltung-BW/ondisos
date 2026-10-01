<?php
declare(strict_types=1);

namespace App\Cli;

use App\Config\TenantContext;
use App\Forms\ValidationResult;
use App\Repositories\FormConfigRepository;
use App\Repositories\FormResourceRepository;
use App\Repositories\FormRevisionRepository;
use App\Repositories\TenantRepository;
use App\Services\SurveyImportService;

/**
 * import-surveys.php: load survey and theme files (e.g. frontend/surveys/*.json) into the database of a tenant.
 *
 * The logic lives here, not in the script, so it is testable. Output goes through the $out/$err callbacks.
 * Exit codes: 0 = nothing wrong, 1 = at least one file was invalid, 2 = usage or setup error.
 */
final class ImportSurveysCommand
{
    private const KNOWN_OPTIONS = ['tenant', 'overwrite', 'dry-run', 'help'];

    public function __construct(
        private readonly TenantRepository $tenants,
        private readonly FormConfigRepository $configs,
        private readonly FormResourceRepository $resources,
        private readonly FormRevisionRepository $revisions,
    ) {
    }

    public static function usage(): string
    {
        return <<<'TXT'
Usage:
  php import-surveys.php [--tenant=<slug>] [--overwrite] [--dry-run] <directory>

Imports every *.json file of <directory> (surveys and themes) into the database of the tenant
(default: "default"). The files go through the same checks as everything entered in the backend;
invalid files are reported and skipped, the others are still imported.

  --tenant=<slug>  tenant to import for (default: default)
  --overwrite      replace resources that exist with different content (the old survey is kept in the
                   history of the forms that use it); without it they are skipped
  --dry-run        check and report only, write nothing

Themes are recognized by the forms' configuration, the name survey_theme.json, or their content.
Run seed-forms.php first for a new tenant so that custom theme names are known.

Docker: the backend container cannot see frontend/. Copy the directory in first:
  docker compose cp frontend/surveys backend:/tmp/surveys
  docker compose exec backend php import-surveys.php /tmp/surveys

TXT;
    }

    /**
     * @param list<string> $args arguments without the script name
     * @param \Closure(string):void $out
     * @param \Closure(string):void $err
     */
    public function run(array $args, \Closure $out, \Closure $err): int
    {
        $cli = new CliArgs($args);

        if ($cli->flag('help')) {
            $out(self::usage());
            return 0;
        }
        $unknown = $cli->unknownOptions(self::KNOWN_OPTIONS);
        if ($unknown !== [] || count($cli->positional) !== 1) {
            if ($unknown !== []) {
                $err('Unknown option: --' . implode(', --', $unknown) . "\n");
            }
            $err(self::usage());
            return 2;
        }

        $dir = $cli->positional[0];
        if (!is_dir($dir)) {
            $err("Error: not a directory: {$dir}\n");
            return 2;
        }

        $slug   = $cli->value('tenant') ?? 'default';
        $tenant = $this->tenants->findBySlug($slug);
        if ($tenant === null) {
            $err("Error: unknown tenant '{$slug}'\n");
            return 2;
        }
        if (!(bool)$tenant['active']) {
            $out("Note: tenant '{$slug}' is inactive.\n");
        }

        TenantContext::initialize((int)$tenant['id']);
        $dryRun    = $cli->flag('dry-run');
        $overwrite = $cli->flag('overwrite');

        $service = new SurveyImportService($this->resources, $this->configs, $this->revisions);
        $results = $service->importDirectory($dir, 'cli', $overwrite, $dryRun);

        $out(($dryRun ? '[dry run] ' : '') . "Tenant: {$tenant['name']} ({$slug})\n");
        if ($results === []) {
            $out("No *.json files found in {$dir}\n");
            return 0;
        }

        $counts = [];
        foreach ($results as $file => $r) {
            $status = $r['status'];
            $counts[$status] = ($counts[$status] ?? 0) + 1;
            $kind = $r['kind'] ?? 'survey';
            $out(sprintf("  %-34s %-7s %s\n", $file, $kind, $this->describe($status, $dryRun)));
            $this->printFindings($r['validation'], $out);
        }

        $out("\n" . $this->summary($counts, $dryRun) . "\n");
        if (($counts[SurveyImportService::STATUS_SKIPPED] ?? 0) > 0) {
            $out("Skipped files already exist with different content; use --overwrite to replace them.\n");
        }

        return ($counts[SurveyImportService::STATUS_INVALID] ?? 0) > 0 ? 1 : 0;
    }

    private function describe(string $status, bool $dryRun): string
    {
        return match ($status) {
            SurveyImportService::STATUS_IMPORTED  => $dryRun ? 'would be imported' : 'imported',
            SurveyImportService::STATUS_UNCHANGED => 'unchanged',
            SurveyImportService::STATUS_SKIPPED   => 'SKIPPED (exists, different content)',
            default                               => 'INVALID',
        };
    }

    /**
     * @param \Closure(string):void $out
     */
    private function printFindings(ValidationResult $v, \Closure $out): void
    {
        foreach ($v->errors() as $e) {
            $out('      error:   ' . ($e['path'] !== '' ? $e['path'] . ': ' : '') . $e['message'] . "\n");
        }
        foreach ($v->warnings() as $w) {
            $out('      warning: ' . ($w['path'] !== '' ? $w['path'] . ': ' : '') . $w['message'] . "\n");
        }
    }

    /** @param array<string,int> $counts */
    private function summary(array $counts, bool $dryRun): string
    {
        $parts = [];
        foreach ([
            SurveyImportService::STATUS_IMPORTED  => $dryRun ? 'would be imported' : 'imported',
            SurveyImportService::STATUS_UNCHANGED => 'unchanged',
            SurveyImportService::STATUS_SKIPPED   => 'skipped',
            SurveyImportService::STATUS_INVALID   => 'invalid',
        ] as $status => $label) {
            if (($counts[$status] ?? 0) > 0) {
                $parts[] = "{$counts[$status]} {$label}";
            }
        }
        return 'Done: ' . implode(', ', $parts) . '.';
    }
}
