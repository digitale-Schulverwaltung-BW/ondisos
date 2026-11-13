<?php
declare(strict_types=1);

namespace App\Services;

use App\Forms\ConflictException;
use App\Forms\Identifiers;
use App\Forms\NotFoundException;
use App\Forms\ServiceResult;
use App\Forms\ThemeValidator;
use App\Forms\ValidationResult;
use App\Repositories\FormConfigRepository;
use App\Repositories\FormResourceRepository;
use App\Repositories\FormRevisionRepository;
use mysqli;

/**
 * The theme editor's operations (3.2.0b): list the themes of a tenant, load one, check, publish, restore.
 *
 * A theme (colors, font, card style) is shared by every form whose config names it ("theme": "survey_theme.json").
 * Rules:
 *  - only themes that exist or that a form refers to can be opened or published; new names come from the config,
 *  - a theme that a form names but that is not stored yet starts with the built-in default (templates/theme/),
 *  - publishing validates first (ThemeValidator) and writes nothing on errors,
 *  - optimistic locking: the caller passes the sha256 it loaded (null = "it did not exist"); a different live
 *    state raises ConflictException instead of overwriting,
 *  - every change leaves a revision; theme revisions live under FormRevisionRepository::THEME_KEY, per name,
 *  - all repositories are tenant-scoped via TenantContext.
 * Both roles (platform and tenant admin) may change theme contents; which theme a form uses stays a platform setting.
 */
class ThemeService
{
    /** How many revisions to keep per theme. */
    public const KEEP_REVISIONS = 50;

    private const KIND = FormResourceRepository::KIND_THEME;
    private const KEY  = FormRevisionRepository::THEME_KEY;

    private \Closure $audit;
    private string $defaultThemePath;

    public function __construct(
        private readonly mysqli $db,
        private readonly FormConfigRepository $configs,
        private readonly FormResourceRepository $resources,
        private readonly FormRevisionRepository $revisions,
        private readonly ThemeValidator $validator = new ThemeValidator(),
        ?\Closure $audit = null,
        ?string $defaultThemePath = null,
    ) {
        $this->audit = $audit ?? static function (string $event, string $name, array $details): void {
            AuditLogger::themeEvent($event, $name, $details);
        };
        $this->defaultThemePath = $defaultThemePath ?? dirname(__DIR__, 2) . '/templates/theme/default.json';
    }

    /**
     * All themes of the tenant: stored ones plus names that forms refer to, with the forms using each.
     *
     * @return list<array{name: string, exists: bool, sha256: ?string, updated_at: ?string, forms: list<string>}>
     */
    public function themes(): array
    {
        $usage  = $this->usageByName();
        $stored = [];
        foreach ($this->resources->listNames(self::KIND) as $row) {
            $stored[$row['name']] = $row;
        }

        $names = array_unique([...array_keys($stored), ...array_keys($usage)]);
        sort($names);

        return array_map(static fn (string $name): array => [
            'name'       => $name,
            'exists'     => isset($stored[$name]),
            'sha256'     => $stored[$name]['sha256'] ?? null,
            'updated_at' => $stored[$name]['updated_at'] ?? null,
            'forms'      => $usage[$name] ?? [],
        ], $names);
    }

    /**
     * One theme for the editor. A theme that is named by a form but not stored yet comes with the built-in default.
     *
     * @return array{name: string, content: string, sha256: ?string, exists: bool, updated_at: ?string, updated_by: ?string, forms: list<string>}
     * @throws NotFoundException the name is invalid, or neither stored nor used by any form of this tenant
     */
    public function load(string $name): array
    {
        $this->assertKnown($name);
        $live  = $this->resources->find(self::KIND, $name);
        $forms = $this->usageByName()[$name] ?? [];

        return [
            'name'       => $name,
            'content'    => $live['content'] ?? $this->defaultTheme(),
            'sha256'     => $live['sha256'] ?? null,
            'exists'     => $live !== null,
            'updated_at' => $live['updated_at'] ?? null,
            'updated_by' => $live['updated_by'] ?? null,
            'forms'      => $forms,
        ];
    }

    /** @return list<string> form keys whose config uses this theme, sorted */
    public function usage(string $name): array
    {
        return $this->usageByName()[$name] ?? [];
    }

    /** Whether the theme can be opened in this tenant (stored, or named by a form). */
    public function exists(string $name): bool
    {
        try {
            $this->assertKnown($name);
            return true;
        } catch (NotFoundException) {
            return false;
        }
    }

    /** Check a theme text without storing anything. */
    public function check(string $json): ValidationResult
    {
        return $this->validator->validate(trim($json))['result'];
    }

    /**
     * Make $json the live theme $name.
     *
     * @param string|null $expectedSha sha256 of the theme when the editor was opened; null if it did not exist then
     * @throws NotFoundException
     * @throws ConflictException the live theme changed since the editor was opened
     */
    public function publish(string $name, string $json, ?string $expectedSha, string $user, ?string $note = null): ServiceResult
    {
        $this->assertKnown($name);
        $json = trim($json);

        $result = $this->validator->validate($json)['result'];
        if (!$result->isValid()) {
            $this->auditRejected($name, $result);
            return new ServiceResult($result);
        }

        return $this->transaction(function () use ($name, $json, $expectedSha, $user, $note, $result): ServiceResult {
            $live = $this->resources->find(self::KIND, $name, forUpdate: true);
            $liveSha = $live['sha256'] ?? null;
            $unchangedSinceLoad = $liveSha === null
                ? $expectedSha === null
                : $expectedSha !== null && hash_equals($liveSha, $expectedSha);
            if (!$unchangedSinceLoad) {
                throw new ConflictException("Das Theme '{$name}' wurde zwischenzeitlich geändert");
            }

            $sha = hash('sha256', $json);
            if ($liveSha !== null && hash_equals($liveSha, $sha)) {
                return new ServiceResult($result, $sha); // nothing changed
            }

            $revId = $this->putLive($name, $json, $live, $note ?? 'Veröffentlicht', $user);
            ($this->audit)('theme_published', $name, ['sha256' => $sha, 'revision' => $revId]);

            return new ServiceResult($result, $sha, $revId);
        });
    }

    /**
     * Revisions of a theme, newest first, without contents.
     *
     * @return list<array{id: int, kind: string, name: ?string, sha256: string, note: ?string, created_by: ?string, created_at: string}>
     * @throws NotFoundException
     */
    public function revisions(string $name, int $limit = self::KEEP_REVISIONS): array
    {
        $this->assertKnown($name);
        return $this->revisions->list(self::KEY, self::KIND, $limit, $name);
    }

    /**
     * Make an earlier revision of the theme live again. Revisions of other themes, forms or tenants are "not found".
     *
     * @throws NotFoundException
     */
    public function restoreRevision(string $name, int $revisionId, string $user): ServiceResult
    {
        $this->assertKnown($name);
        $rev = $this->revisions->find($revisionId);
        if ($rev === null || $rev['form_key'] !== self::KEY || $rev['kind'] !== self::KIND || $rev['name'] !== $name) {
            throw new NotFoundException('Revision nicht gefunden');
        }

        // Content was valid when it went live, but rules may have become stricter: check again.
        $result = $this->validator->validate($rev['content'])['result'];
        if (!$result->isValid()) {
            $this->auditRejected($name, $result);
            return new ServiceResult($result);
        }

        return $this->transaction(function () use ($name, $rev, $user, $result): ServiceResult {
            $live = $this->resources->find(self::KIND, $name, forUpdate: true);
            if ($live !== null && hash_equals($live['sha256'], $rev['sha256'])) {
                return new ServiceResult($result, $rev['sha256']); // already live
            }

            $revId = $this->putLive($name, $rev['content'], $live, "Wiederhergestellt aus Revision #{$rev['id']}", $user);
            ($this->audit)('theme_rolled_back', $name, ['to_revision' => $rev['id'], 'revision' => $revId]);

            return new ServiceResult($result, $rev['sha256'], $revId);
        });
    }

    /** The built-in default theme (SurveyJS 3 format, looks like the forms did with SurveyJS 2). */
    public function defaultTheme(): string
    {
        $content = is_readable($this->defaultThemePath) ? file_get_contents($this->defaultThemePath) : false;
        if ($content === false) {
            throw new \RuntimeException('Vorgabe-Theme nicht gefunden: ' . $this->defaultThemePath);
        }
        return trim($content);
    }

    // =========================================================================
    // Internals
    // =========================================================================

    /**
     * Replace the live theme, keeping history: the old live state is stored first if it is not the newest revision
     * yet (e.g. imported from files), then the new one.
     *
     * @param array{content: string, sha256: string}|null $live
     * @return int id of the revision of the new live state
     */
    private function putLive(string $name, string $content, ?array $live, string $note, string $user): int
    {
        if ($live !== null) {
            $this->revisions->keepThemeState($name, $live['content'], 'Stand vor der Änderung', $user);
        }
        $this->resources->save(self::KIND, $name, $content, $user);
        $revId = $this->revisions->add(self::KEY, self::KIND, $name, $content, $note, $user);
        $this->revisions->pruneOldest(self::KEY, self::KIND, self::KEEP_REVISIONS, $name);

        return $revId;
    }

    /**
     * @throws NotFoundException invalid name, or a name that is neither stored nor used by a form
     */
    private function assertKnown(string $name): void
    {
        if (!Identifiers::isValidResourceName($name)) {
            throw new NotFoundException('Theme nicht gefunden');
        }
        if ($this->resources->find(self::KIND, $name) === null && !isset($this->usageByName()[$name])) {
            throw new NotFoundException("Theme '{$name}' nicht gefunden");
        }
    }

    /** @return array<string, list<string>> theme name → form keys using it (valid names only) */
    private function usageByName(): array
    {
        $usage = [];
        foreach ($this->configs->listKeys() as $formKey) {
            $theme = $this->configs->find($formKey)['config']['theme'] ?? null;
            if (is_string($theme) && Identifiers::isValidResourceName($theme)) {
                $usage[$theme][] = $formKey;
            }
        }
        foreach ($usage as &$forms) {
            sort($forms);
        }
        unset($forms);
        ksort($usage);

        return $usage;
    }

    /** Rejected theme content is a security signal: log how many problems and where, never the content. */
    private function auditRejected(string $name, ValidationResult $result): void
    {
        $paths = array_slice(array_values(array_unique(array_column($result->errors(), 'path'))), 0, 5);
        ($this->audit)('theme_rejected', $name, ['errors' => count($result->errors()), 'paths' => $paths]);
    }

    /**
     * @template T
     * @param callable(): T $fn
     * @return T
     */
    private function transaction(callable $fn): mixed
    {
        $this->db->begin_transaction();
        try {
            $result = $fn();
            $this->db->commit();
            return $result;
        } catch (\Throwable $e) {
            $this->db->rollback();
            throw $e;
        }
    }
}
