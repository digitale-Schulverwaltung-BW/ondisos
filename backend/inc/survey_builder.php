<?php
declare(strict_types=1);

/**
 * Data of the graphical survey builder (the "Grafisch" tab of form_survey.php), embedded into the page as JSON.
 *
 * The script (backend/public/assets/survey-builder/) never decides what is valid: the server's rules, the texts, the
 * names the published survey already used and the findings of the last check come from here.
 */

use App\Forms\SurveyBlocks;
use App\Forms\SurveyBuilderFindings;
use App\Forms\SurveyBuilderRules;
use App\Services\MessageService as M;

/**
 * @param array<string,mixed> $view   SurveyEditorController::load()
 * @param array<string,mixed>|null $report SurveyEditorController::check() of the text shown, if any
 * @return array<string,mixed>
 */
function sb_config(array $view, ?array $report): array
{
    return [
        'rules'    => SurveyBuilderRules::toArray(),
        'messages' => M::getAll()['builder'] ?? [],
        // names of the published survey: a new field must not take the name of one that already has answers
        'taken'    => $view['live']['fields'] ?? [],
        'findings' => SurveyBuilderFindings::fromReport($report),
        // building blocks (AP6): groups of standard fields; ASV names for school registrations
        'blocks'   => sb_blocks(),
        // JSON errors point at lines, which only the JSON tab shows
        'startTab' => ($report !== null && !$report['valid']) ? 'json' : 'graphic',
    ];
}

/**
 * The building blocks, or none if their files are broken: the builder works without them, the defect goes to the log.
 *
 * @return list<array<string,mixed>>
 */
function sb_blocks(): array
{
    try {
        return SurveyBlocks::all();
    } catch (\RuntimeException $e) {
        error_log('Survey builder: building blocks unavailable: ' . $e->getMessage());
        return [];
    }
}

/** JSON for a <script type="application/json"> element: nothing in it can end the element or start another. */
function sb_json(array $data): string
{
    return json_encode($data, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
}
