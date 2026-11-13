<?php
declare(strict_types=1);

use App\Services\MessageService as M;

/**
 * The collapsible "difference to the published version" of the survey and theme editors.
 *
 * @param array{hunks: list<list<array{op:string, text:string}>>, added:int, removed:int, too_large:bool, identical:bool} $diff from SurveyDiff::lines()
 * Needs ff_e() (inc/form_fields.php).
 */
function editor_diff_html(array $diff): string
{
    $changed = !$diff['identical'] && !$diff['too_large'];
    $out = '<details class="mt-3"' . ($changed ? ' open' : '') . '><summary>'
         . ff_e(M::get('survey_editor.diff.title', 'Unterschied zur veröffentlichten Fassung'));
    if ($changed) {
        $out .= ' <span class="text-success">+' . (int)$diff['added'] . '</span> <span class="text-danger">−' . (int)$diff['removed'] . '</span>';
    }
    $out .= '</summary>';

    if ($diff['identical']) {
        return $out . '<p class="text-muted mt-2">' . ff_e(M::get('survey_editor.diff.identical', 'Keine Änderung gegenüber der veröffentlichten Fassung.')) . '</p></details>';
    }
    if ($diff['too_large']) {
        return $out . '<p class="text-muted mt-2">' . ff_e(M::get('survey_editor.diff.too_large', 'Zu viele Änderungen für die Anzeige.')) . '</p></details>';
    }

    $out .= '<div class="border rounded mt-2 small" style="max-height: 24rem; overflow: auto;">';
    foreach ($diff['hunks'] as $n => $hunk) {
        if ($n > 0) {
            $out .= '<div class="text-muted px-2 bg-light">⋯</div>';
        }
        foreach ($hunk as $line) {
            $class = $line['op'] === '+' ? 'bg-success-subtle' : ($line['op'] === '-' ? 'bg-danger-subtle' : '');
            $out .= '<div class="font-monospace px-2 text-nowrap ' . $class . '">' . ($line['op'] === ' ' ? '&nbsp;' : ff_e($line['op']))
                  . '&nbsp;' . ff_e($line['text']) . '</div>';
        }
    }
    return $out . '</div></details>';
}
