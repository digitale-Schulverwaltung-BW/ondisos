<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Forms\ConflictException;
use App\Forms\JsonLocator;
use App\Forms\NotFoundException;
use App\Forms\SurveyDiff;
use App\Forms\ValidationResult;
use App\Services\ThemeService;

/**
 * Behind themes.php and theme_edit.php (3.2.0b AP2): view models and actions of the theme editor
 * (edit JSON → check → publish, restore from history).
 *
 * Plain arrays in and out, so the page has no rules of its own; ThemeService does the work and the checking.
 * Runs in the current tenant (TenantContext); a theme of another tenant is "not found".
 * Both editor roles may use it (decision 2 of PLAN-3.2.0b).
 */
class ThemeEditorController
{
    /** How many revisions the page lists. */
    public const HISTORY_LIMIT = 30;

    public function __construct(
        private readonly ThemeService $service,
        private readonly string $user,
    ) {
    }

    /**
     * @return list<array{name: string, exists: bool, sha256: ?string, updated_at: ?string, forms: list<string>}>
     */
    public function listThemes(): array
    {
        return $this->service->themes();
    }

    /**
     * @return array{
     *   name: string, content: string, sha256: ?string, exists: bool, updated_at: ?string, updated_by: ?string,
     *   forms: list<string>, revisions: list<array<string,mixed>>
     * }|null null if the theme is unknown in this tenant
     */
    public function load(string $name): ?array
    {
        try {
            $theme = $this->service->load($name);
            $theme['revisions'] = $this->service->revisions($name, self::HISTORY_LIMIT);
        } catch (NotFoundException) {
            return null;
        }
        return $theme;
    }

    /**
     * Check a text without storing anything: findings with line numbers and a diff against the live theme.
     *
     * @return array{
     *   valid: bool,
     *   errors: list<array{path: string, message: string, line: ?int, column: ?int}>,
     *   warnings: list<array{path: string, message: string, line: ?int}>,
     *   diff: ?array<string,mixed>, forms: list<string>, first_publish: bool
     * }|null null if the theme is unknown in this tenant
     */
    public function check(string $name, string $text): ?array
    {
        try {
            $theme = $this->service->load($name);
        } catch (NotFoundException) {
            return null;
        }

        $text    = trim($text);
        $located = JsonLocator::analyze($text);
        $result  = $this->service->check($text);

        $errors = [];
        if ($located['error'] !== null) {
            // The validator only says "not valid JSON"; the locator knows where.
            $errors[] = ['path' => '', 'message' => $located['error']['message'], 'line' => $located['error']['line'], 'column' => $located['error']['column']];
        } else {
            foreach ($result->errors() as $e) {
                $errors[] = ['path' => $e['path'], 'message' => $e['message'], 'line' => $located['lines'][$e['path']] ?? null, 'column' => null];
            }
        }
        $warnings = [];
        foreach ($result->warnings() as $w) {
            $warnings[] = ['path' => $w['path'], 'message' => $w['message'], 'line' => $located['lines'][$w['path']] ?? null];
        }

        return [
            'valid'         => $errors === [],
            'errors'        => $errors,
            'warnings'      => $warnings,
            'diff'          => $errors === [] && $theme['exists'] ? SurveyDiff::lines($theme['content'], $text) : null,
            'forms'         => $theme['forms'],
            'first_publish' => !$theme['exists'],
        ];
    }

    /**
     * Make the text the live theme, if nobody else published since the editor was opened.
     *
     * @param string|null $expectedSha sha256 shown when the editor was opened ('' or null: the theme did not exist then)
     * @return array{status: 'published'|'unchanged'|'invalid'|'conflict'|'not_found', report: ?array<string,mixed>}
     */
    public function publish(string $name, string $text, ?string $expectedSha, ?string $note): array
    {
        $report = $this->check($name, $text);
        if ($report === null) {
            return ['status' => 'not_found', 'report' => null];
        }
        $expectedSha = $expectedSha !== null && $expectedSha !== '' ? $expectedSha : null;
        $note        = $note !== null && trim($note) !== '' ? mb_substr(trim($note), 0, 255) : null;

        try {
            // Also when the report has errors: the service checks again and logs the rejection (theme_rejected).
            $result = $this->service->publish($name, $text, $expectedSha, $this->user, $note);
        } catch (ConflictException) {
            return ['status' => 'conflict', 'report' => $this->check($name, $text)]; // diff against the new live state
        } catch (NotFoundException) {
            return ['status' => 'not_found', 'report' => null];
        }

        if (!$result->ok()) {
            return ['status' => 'invalid', 'report' => $report];
        }
        return ['status' => $result->revisionId === null ? 'unchanged' : 'published', 'report' => $report];
    }

    /**
     * @return array{status: 'restored'|'unchanged'|'invalid'|'not_found', result: ValidationResult}
     */
    public function restore(string $name, int $revisionId): array
    {
        try {
            $result = $this->service->restoreRevision($name, $revisionId, $this->user);
        } catch (NotFoundException) {
            return ['status' => 'not_found', 'result' => new ValidationResult()];
        }
        if (!$result->ok()) {
            return ['status' => 'invalid', 'result' => $result->validation];
        }
        return ['status' => $result->revisionId === null ? 'unchanged' : 'restored', 'result' => $result->validation];
    }

    /**
     * May the live preview apply this unsaved theme text (AP3)? Only what ThemeValidator accepts; nothing is stored.
     *
     * @return array{status: 'ok'|'invalid'|'not_found', errors: list<array{path: string, message: string}>}
     *   at most SurveyPreviewController::MAX_REPORTED errors; the full report stays with "Prüfen"
     */
    public function previewVerdict(string $name, string $text): array
    {
        if (!$this->service->exists($name)) {
            return ['status' => 'not_found', 'errors' => []];
        }
        $result = $this->service->check($text);
        if ($result->isValid()) {
            return ['status' => 'ok', 'errors' => []];
        }
        $errors = array_map(
            static fn (array $e): array => ['path' => (string)$e['path'], 'message' => (string)$e['message']],
            array_slice($result->errors(), 0, SurveyPreviewController::MAX_REPORTED)
        );
        return ['status' => 'invalid', 'errors' => $errors];
    }

    /** The built-in default, for "Auf Vorgabe zurücksetzen" (fills the editor, stores nothing). */
    public function defaultTheme(): string
    {
        return $this->service->defaultTheme();
    }
}
