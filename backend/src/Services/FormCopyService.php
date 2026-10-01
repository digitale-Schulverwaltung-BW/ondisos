<?php
declare(strict_types=1);

namespace App\Services;

use App\Config\TenantContext;
use App\Forms\AccessDeniedException;
use App\Forms\FormConfigSchema;
use App\Forms\FormConfigValidator;
use App\Forms\Identifiers;
use App\Forms\SurveyLinter;
use App\Forms\SurveyValidator;
use App\Forms\ThemeValidator;
use App\Repositories\FormConfigRepository;
use App\Repositories\FormResourceRepository;
use App\Repositories\FormRevisionRepository;
use App\Repositories\TenantRepository;
use mysqli;

/**
 * Copies forms (config + the survey and theme the config refers to) from one tenant to another.
 *
 * Meant for onboarding a school: a new tenant starts with no forms; this gives it the ones of an existing tenant.
 *
 * A copy must never carry one school's data into another school's operation:
 *  - recipients (notify_email) and the PDF logo are NOT copied (FormConfigSchema::copyBehavior),
 *  - texts that often name the source school are copied but reported for review,
 *  - survey texts containing e-mail addresses or phone numbers are reported,
 *  - drafts, history, submissions, uploads and the tenant secret are never touched.
 * A form without recipient and with db=false is not shown by the frontend (FormConfig::discardsSubmissions), so the
 * copy is safe by default: nothing is lost if the school forgets to enter its address.
 *
 * Only platform admins may copy: it reads another tenant's forms. The check is done here, not only in the pages.
 * Source and target are explicit parameters; the current TenantContext is switched with TenantContext::runAs() so
 * all access still goes through the tenant-filtered repositories, and is restored afterwards.
 * Everything is written in one transaction: if anything fails, nothing is copied.
 */
class FormCopyService
{
    public const STATUS_COPIED      = 'copied';
    public const STATUS_OVERWRITTEN = 'overwritten';
    public const STATUS_SKIPPED     = 'skipped';   // exists in the target and overwrite was not requested
    public const STATUS_MISSING     = 'missing';   // not in the source
    public const STATUS_INVALID     = 'invalid';   // source data does not pass today's validators

    public const RESOURCE_COPIED      = 'copied';
    public const RESOURCE_UNCHANGED   = 'unchanged';
    public const RESOURCE_OVERWRITTEN = 'overwritten';
    public const RESOURCE_KEPT        = 'kept';    // exists in the target with different content, not replaced

    private \Closure $audit;

    public function __construct(
        private readonly mysqli $db,
        private readonly TenantRepository $tenants,
        private readonly FormConfigRepository $configs,
        private readonly FormResourceRepository $resources,
        private readonly FormRevisionRepository $revisions,
        private readonly FormConfigValidator $configValidator = new FormConfigValidator(),
        private readonly SurveyValidator $surveyValidator = new SurveyValidator(),
        private readonly ThemeValidator $themeValidator = new ThemeValidator(),
        private readonly SurveyLinter $linter = new SurveyLinter(),
        ?\Closure $audit = null,
    ) {
        $this->audit = $audit ?? static function (string $event, string $formKey, array $details): void {
            AuditLogger::formEvent($event, $formKey, $details);
        };
    }

    /**
     * @param list<string>|null $formKeys form keys to copy; null = every form of the source
     * @return array{
     *   from: array{id:int,slug:string,name:string}, to: array{id:int,slug:string,name:string}, dry_run: bool,
     *   forms: array<string, array{
     *     status: string,
     *     recipient_cleared: bool,
     *     hidden_until_recipient: bool,
     *     review: list<string>,
     *     resources: list<array{kind:string,name:string,status:string}>,
     *     warnings: list<array{path:string,message:string}>,
     *     errors: list<array{path:string,message:string}>
     *   }>
     * }
     * @throws AccessDeniedException the role may not copy
     * @throws \InvalidArgumentException unknown/inactive tenant, or source and target are the same
     */
    public function copy(int $fromTenantId, int $toTenantId, ?array $formKeys, bool $overwrite, string $role, string $user, bool $dryRun = false): array
    {
        if ($role !== FormConfigSchema::ROLE_PLATFORM) {
            ($this->audit)('form_copy_denied', '', ['from_tenant' => $fromTenantId, 'to_tenant' => $toTenantId, 'role' => $role]);
            throw new AccessDeniedException('Nur Plattform-Administratoren dürfen Formulare zwischen Tenants kopieren.');
        }
        if ($fromTenantId === $toTenantId) {
            throw new \InvalidArgumentException('Quelle und Ziel sind derselbe Tenant.');
        }
        $from = $this->activeTenant($fromTenantId, 'Quell-Tenant');
        $to   = $this->activeTenant($toTenantId, 'Ziel-Tenant');

        // ---- read the source -------------------------------------------------------------------
        /** @var array<string, array{config:array<string,mixed>, resources:list<array{kind:string,name:string,content:string}>}> $plan */
        $plan   = [];
        $report = [];
        TenantContext::runAs($fromTenantId, function () use ($formKeys, &$plan, &$report): void {
            $keys = $formKeys ?? $this->configs->listKeys();
            foreach ($keys as $key) {
                $row = Identifiers::isValidFormKey($key) ? $this->configs->find($key) : null;
                if ($row === null) {
                    $report[$key] = $this->entry(self::STATUS_MISSING);
                    continue;
                }
                $found  = $this->readResources($row['config']);
                $entry  = $this->entry(self::STATUS_COPIED);
                $errors = $this->configValidator->validate($row['config'])->errors();
                foreach ($found['errors'] as $e) {
                    $errors[] = $e;
                }
                if ($errors !== []) {
                    $entry['status'] = self::STATUS_INVALID;
                    $entry['errors'] = $errors;
                    $report[$key]    = $entry;
                    continue;
                }

                [$config, $cleared, $review] = $this->prepareConfig($row['config']);
                $entry['recipient_cleared']      = $cleared;
                $entry['review']                 = $review;
                $entry['hidden_until_recipient'] = !($config['db'] ?? true);
                $entry['warnings']               = $found['warnings'];

                $report[$key] = $entry;
                $plan[$key]   = ['config' => $config, 'resources' => $found['resources']];
            }
        });

        // The target must not end up with more forms than a tenant may have (existing keys do not count twice).
        $existingInTarget = TenantContext::runAs($toTenantId, fn (): array => $this->configs->listKeys());
        $newKeys          = array_diff(array_keys($plan), $existingInTarget);
        if (count($existingInTarget) + count($newKeys) > FormPublishService::MAX_FORMS_PER_TENANT) {
            throw new \InvalidArgumentException('Das Ziel würde mehr als ' . FormPublishService::MAX_FORMS_PER_TENANT . ' Formulare haben.');
        }

        // ---- write the target ------------------------------------------------------------------
        $slug = (string)$from['slug'];
        $apply = function () use (&$plan, &$report, $overwrite, $dryRun, $slug, $user): void {
            $done = [];
            foreach ($plan as $key => $item) {
                $existing = $this->configs->find($key);
                if ($existing !== null && !$overwrite) {
                    $report[$key]['status'] = self::STATUS_SKIPPED;
                    continue;
                }

                // Survey/theme first (a theme can be shared by several forms: handled once).
                foreach ($item['resources'] as $res) {
                    $id = $res['kind'] . ':' . $res['name'];
                    if (!isset($done[$id])) {
                        $done[$id] = $this->putResource($res, $overwrite, $dryRun, $user, $slug);
                    }
                    $report[$key]['resources'][] = ['kind' => $res['kind'], 'name' => $res['name'], 'status' => $done[$id]];
                }

                if ($existing !== null) {
                    $report[$key]['status'] = self::STATUS_OVERWRITTEN;
                    if (!$dryRun) {
                        $this->keepRevision($key, $existing['json'], 'Stand vor dem Kopieren', $user);
                        $this->configs->update($key, $item['config'], null);
                    }
                } elseif (!$dryRun) {
                    $this->configs->insert($key, $item['config']);
                }
                if (!$dryRun) {
                    $this->revisions->add($key, FormRevisionRepository::KIND_CONFIG, null, FormConfigRepository::encode($item['config']), "kopiert von {$slug}", $user);
                }
            }
        };

        if ($dryRun) {
            TenantContext::runAs($toTenantId, $apply);
        } else {
            $this->db->begin_transaction();
            try {
                TenantContext::runAs($toTenantId, $apply);
                $this->db->commit();
            } catch (\Throwable $e) {
                $this->db->rollback();
                throw $e;
            }
            foreach ($report as $key => $entry) {
                if (in_array($entry['status'], [self::STATUS_COPIED, self::STATUS_OVERWRITTEN], true)) {
                    ($this->audit)('form_copied', $key, ['from_tenant' => $fromTenantId, 'to_tenant' => $toTenantId, 'status' => $entry['status']]);
                }
            }
        }

        return [
            'from'    => ['id' => $fromTenantId, 'slug' => $slug, 'name' => (string)$from['name']],
            'to'      => ['id' => $toTenantId, 'slug' => (string)$to['slug'], 'name' => (string)$to['name']],
            'dry_run' => $dryRun,
            'forms'   => $report,
        ];
    }

    // =========================================================================
    // Internals
    // =========================================================================

    /** @return array<string,mixed> */
    private function activeTenant(int $id, string $label): array
    {
        $tenant = $this->tenants->findById($id);
        if ($tenant === null) {
            throw new \InvalidArgumentException("{$label} nicht gefunden.");
        }
        if (!(bool)$tenant['active']) {
            throw new \InvalidArgumentException("{$label} ist deaktiviert.");
        }
        return $tenant;
    }

    /** @return array{status:string,recipient_cleared:bool,hidden_until_recipient:bool,review:list<string>,resources:list<array{kind:string,name:string,status:string}>,warnings:list<array{path:string,message:string}>,errors:list<array{path:string,message:string}>} */
    private function entry(string $status): array
    {
        return ['status' => $status, 'recipient_cleared' => false, 'hidden_until_recipient' => false, 'review' => [], 'resources' => [], 'warnings' => [], 'errors' => []];
    }

    /**
     * Apply the copy rules to a config.
     *
     * @param array<string,mixed> $config
     * @return array{0: array<string,mixed>, 1: bool, 2: list<string>} [config for the target, whether a recipient was dropped, paths to review]
     */
    private function prepareConfig(array $config): array
    {
        $review  = [];
        $cleared = false;

        foreach (FormConfigSchema::fields() as $path => $field) {
            $behavior = FormConfigSchema::copyBehavior($path);
            if ($behavior === 'keep') {
                continue;
            }
            [$present, $value] = self::lookup($config, $path);
            if (!$present || $value === null || $value === '' || $value === [] || $value === false) {
                continue;
            }
            if ($behavior === 'clear') {
                self::unset($config, $path);
                $cleared = $cleared || $path === 'notify_email';
            } else {
                $review[] = $path;
            }
        }

        return [$config, $cleared, $review];
    }

    /**
     * The survey and theme the config refers to (as stored text), validated with today's rules.
     *
     * @param array<string,mixed> $config
     * @return array{resources: list<array{kind:string,name:string,content:string}>, errors: list<array{path:string,message:string}>, warnings: list<array{path:string,message:string}>}
     */
    private function readResources(array $config): array
    {
        $out = ['resources' => [], 'errors' => [], 'warnings' => []];

        foreach ([['form', FormResourceRepository::KIND_SURVEY], ['theme', FormResourceRepository::KIND_THEME]] as [$key, $kind]) {
            $name = $config[$key] ?? null;
            if (!is_string($name) || !Identifiers::isValidResourceName($name)) {
                continue;
            }
            $row = $this->resources->find($kind, $name);
            if ($row === null) {
                continue; // the source still uses the file in the frontend: nothing to copy, the target uses the same file
            }

            if ($kind === FormResourceRepository::KIND_SURVEY) {
                ['result' => $r, 'survey' => $survey] = $this->surveyValidator->validate($row['content']);
                if ($survey !== null) {
                    foreach ($this->linter->contactData($survey)->warnings() as $w) {
                        $out['warnings'][] = ['path' => $name . ': ' . $w['path'], 'message' => $w['message']];
                    }
                }
            } else {
                $r = $this->themeValidator->validate($row['content'])['result'];
            }
            foreach ($r->errors() as $e) {
                $out['errors'][] = ['path' => $name . ($e['path'] !== '' ? ': ' . $e['path'] : ''), 'message' => $e['message']];
            }
            $out['resources'][] = ['kind' => $kind, 'name' => $name, 'content' => $row['content']];
        }

        return $out;
    }

    /**
     * Store a survey/theme in the target unless it is already there.
     *
     * @param array{kind:string,name:string,content:string} $res
     */
    private function putResource(array $res, bool $overwrite, bool $dryRun, string $user, string $sourceSlug): string
    {
        $existing = $this->resources->find($res['kind'], $res['name']);
        if ($existing === null) {
            if (!$dryRun) {
                $this->resources->save($res['kind'], $res['name'], $res['content'], "{$user} (kopiert von {$sourceSlug})");
            }
            return self::RESOURCE_COPIED;
        }
        if (hash_equals($existing['sha256'], hash('sha256', $res['content']))) {
            return self::RESOURCE_UNCHANGED;
        }
        if (!$overwrite) {
            return self::RESOURCE_KEPT;
        }
        if (!$dryRun) {
            $this->resources->save($res['kind'], $res['name'], $res['content'], "{$user} (kopiert von {$sourceSlug})");
        }
        return self::RESOURCE_OVERWRITTEN;
    }

    private function keepRevision(string $formKey, string $json, string $note, string $user): void
    {
        $latest = $this->revisions->latest($formKey, FormRevisionRepository::KIND_CONFIG);
        if ($latest === null || !hash_equals($latest['sha256'], hash('sha256', $json))) {
            $this->revisions->add($formKey, FormRevisionRepository::KIND_CONFIG, null, $json, $note, $user);
        }
    }

    /** @return array{0:bool,1:mixed} */
    private static function lookup(array $data, string $path): array
    {
        $node = $data;
        foreach (explode('.', $path) as $segment) {
            if (!is_array($node) || !array_key_exists($segment, $node)) {
                return [false, null];
            }
            $node = $node[$segment];
        }
        return [true, $node];
    }

    private static function unset(array &$data, string $path): void
    {
        $segments = explode('.', $path);
        $last     = array_pop($segments);
        $node     = &$data;
        foreach ($segments as $segment) {
            if (!isset($node[$segment]) || !is_array($node[$segment])) {
                return;
            }
            $node = &$node[$segment];
        }
        unset($node[$last]);
    }
}
