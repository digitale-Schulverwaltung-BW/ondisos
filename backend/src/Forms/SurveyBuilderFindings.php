<?php
declare(strict_types=1);

namespace App\Forms;

/**
 * Findings of a survey check (SurveyEditorController::check) for the graphical builder: every finding with the path of
 * the element it belongs to, so the builder can mark that element. The check reports paths as text
 * ("pages[0].elements[1].name"); the builder works with paths as lists (["pages", 0, "elements", 1, "name"]).
 *
 * A finding without a path (the name-or-e-mail hint, JSON errors) belongs to the survey as a whole.
 */
final class SurveyBuilderFindings
{
    /**
     * @return list<string|int> [] for an empty or unreadable path
     */
    public static function parsePath(string $path): array
    {
        if ($path === '') {
            return [];
        }
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*(?:\[\d+\])*(?:\.[A-Za-z_][A-Za-z0-9_]*(?:\[\d+\])*)*$/', $path) !== 1) {
            return [];
        }
        preg_match_all('/([A-Za-z_][A-Za-z0-9_]*)|\[(\d+)\]/', $path, $m, PREG_SET_ORDER);
        $out = [];
        foreach ($m as $token) {
            $out[] = ($token[2] ?? '') !== '' ? (int)$token[2] : $token[1];
        }
        return $out;
    }

    /**
     * @param array{errors?: list<array{path:string,message:string}>, warnings?: list<array{path:string,message:string}>}|null $report
     * @return list<array{path: list<string|int>, message: string, severity: 'error'|'warning'}>
     */
    public static function fromReport(?array $report): array
    {
        if ($report === null) {
            return [];
        }
        $out = [];
        foreach (['error' => $report['errors'] ?? [], 'warning' => $report['warnings'] ?? []] as $severity => $list) {
            foreach ($list as $finding) {
                $out[] = ['path' => self::parsePath((string)$finding['path']), 'message' => (string)$finding['message'], 'severity' => $severity];
            }
        }
        return $out;
    }
}
