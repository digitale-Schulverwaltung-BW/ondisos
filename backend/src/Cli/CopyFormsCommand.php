<?php
declare(strict_types=1);

namespace App\Cli;

use App\Forms\AccessDeniedException;
use App\Forms\FormConfigSchema;
use App\Repositories\TenantRepository;
use App\Services\FormCopyService;

/**
 * copy-forms.php: copy forms (config + survey + theme) from one tenant to another, e.g. to equip a new school.
 *
 * Exit codes: 0 = done (also if forms were skipped), 1 = a form could not be copied (invalid source data),
 * 2 = usage or setup error.
 */
final class CopyFormsCommand
{
    private const KNOWN_OPTIONS = ['from', 'to', 'forms', 'overwrite', 'dry-run', 'help'];

    public function __construct(
        private readonly TenantRepository $tenants,
        private readonly FormCopyService $service,
    ) {
    }

    public static function usage(): string
    {
        return <<<'TXT'
Usage:
  php copy-forms.php --from=<slug> --to=<slug> [--forms=bs,vabo] [--overwrite] [--dry-run]

Copies the forms of one tenant to another (configuration plus the survey and theme they use).

  --from=<slug>     source tenant
  --to=<slug>       target tenant
  --forms=a,b       only these forms (default: all forms of the source)
  --overwrite       replace forms/surveys that already exist in the target (the old state stays in the history);
                    without it existing forms are skipped
  --dry-run         report what would happen, write nothing

What is NOT copied: recipients (notify_email) and the PDF logo (they belong to the source school), drafts, history,
submissions, uploads, the tenant secret. Texts that often name the source school are copied and listed for review.
A copied form without recipient and without db storage is not shown by the frontend until a recipient is set.

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
        if ($unknown !== [] || $cli->positional !== [] || $cli->value('from') === null || $cli->value('to') === null) {
            if ($unknown !== []) {
                $err('Unknown option: --' . implode(', --', $unknown) . "\n");
            }
            $err(self::usage());
            return 2;
        }

        $fromSlug = (string)$cli->value('from');
        $toSlug   = (string)$cli->value('to');
        $from = $this->tenants->findBySlug($fromSlug);
        $to   = $this->tenants->findBySlug($toSlug);
        if ($from === null || $to === null) {
            $err("Error: unknown tenant '" . ($from === null ? $fromSlug : $toSlug) . "'\n");
            return 2;
        }

        $keys = $cli->value('forms');
        $keys = $keys === null ? null : array_values(array_filter(array_map('trim', explode(',', $keys)), static fn (string $k) => $k !== ''));
        $dryRun = $cli->flag('dry-run');

        try {
            // The command line is the operator of the installation: platform role.
            $report = $this->service->copy((int)$from['id'], (int)$to['id'], $keys, $cli->flag('overwrite'), FormConfigSchema::ROLE_PLATFORM, 'cli', $dryRun);
        } catch (AccessDeniedException | \InvalidArgumentException $e) {
            $err('Error: ' . $e->getMessage() . "\n");
            return 2;
        }

        $out(($dryRun ? '[dry run] ' : '') . "From: {$report['from']['name']} ({$fromSlug})  →  To: {$report['to']['name']} ({$toSlug})\n");
        if ($report['forms'] === []) {
            $out("The source tenant has no forms.\n");
            return 0;
        }

        $counts = [];
        $needRecipient = [];
        foreach ($report['forms'] as $key => $f) {
            $counts[$f['status']] = ($counts[$f['status']] ?? 0) + 1;
            $out(sprintf("  %-28s %s\n", $key, $this->label($f['status'], $dryRun)));
            foreach ($f['errors'] as $e) {
                $out('      error:   ' . $e['path'] . ($e['path'] !== '' ? ': ' : '') . $e['message'] . "\n");
            }
            foreach ($f['resources'] as $r) {
                $out("      {$r['kind']} {$r['name']}: {$r['status']}\n");
            }
            if (in_array($f['status'], [FormCopyService::STATUS_COPIED, FormCopyService::STATUS_OVERWRITTEN], true)) {
                if ($f['review'] !== []) {
                    $out('      please check these texts for the old school\'s name/contact: ' . implode(', ', $f['review']) . "\n");
                }
                foreach ($f['warnings'] as $w) {
                    $out('      warning: ' . $w['path'] . ': ' . $w['message'] . "\n");
                }
                if ($f['recipient_cleared']) {
                    $needRecipient[] = $key . ($f['hidden_until_recipient'] ? ' (hidden until set)' : '');
                }
            }
        }

        $parts = [];
        foreach ($counts as $status => $n) {
            $parts[] = "{$n} {$status}";
        }
        $out("\nDone: " . implode(', ', $parts) . ".\n");

        if ($needRecipient !== []) {
            $out("\nNEXT: enter the recipient (notify_email) of the new school for: " . implode(', ', $needRecipient) . "\n"
               . "      (backend → Formulare → Bearbeiten). It was deliberately not copied.\n");
        }

        return ($counts[FormCopyService::STATUS_INVALID] ?? 0) > 0 ? 1 : 0;
    }

    private function label(string $status, bool $dryRun): string
    {
        return match ($status) {
            FormCopyService::STATUS_COPIED      => $dryRun ? 'would be copied' : 'copied',
            FormCopyService::STATUS_OVERWRITTEN => $dryRun ? 'would be overwritten' : 'overwritten',
            FormCopyService::STATUS_SKIPPED     => 'skipped (exists in the target; --overwrite replaces it)',
            FormCopyService::STATUS_MISSING     => 'not found in the source',
            default                             => 'INVALID (not copied)',
        };
    }
}
