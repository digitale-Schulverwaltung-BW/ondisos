<?php
declare(strict_types=1);

namespace App\Forms;

/**
 * What changes between two versions of a survey, for the "before you publish" view.
 *
 * fields(): which data fields appear, disappear or change type. This is what matters to the school: removed or
 * renamed fields no longer show up in new submissions, Excel exports, prefill links and PDFs (old entries keep their data).
 * lines(): a line diff of the pretty-printed JSON, grouped in hunks with a little context.
 */
final class SurveyDiff
{
    /** Longest edit script lines() will compute; beyond that the diff is reported as too large to show. */
    private const MAX_EDITS = 600;

    /**
     * @param array<string,mixed> $old decoded survey
     * @param array<string,mixed> $new decoded survey
     * @return array{added: list<string>, removed: list<string>, type_changed: list<array{name:string,from:string,to:string}>}
     */
    public static function fields(array $old, array $new): array
    {
        $a = SurveyFieldExtractor::fields($old);
        $b = SurveyFieldExtractor::fields($new);

        $changed = [];
        foreach (array_intersect_key($b, $a) as $name => $info) {
            if ($info['type'] !== $a[$name]['type']) {
                $changed[] = ['name' => (string)$name, 'from' => $a[$name]['type'], 'to' => $info['type']];
            }
        }

        return [
            'added'        => array_map('strval', array_keys(array_diff_key($b, $a))),
            'removed'      => array_map('strval', array_keys(array_diff_key($a, $b))),
            'type_changed' => $changed,
        ];
    }

    /**
     * Pretty-printed JSON with a stable layout, or null if $json is not valid JSON.
     * Objects stay objects ("{}" does not become "[]").
     */
    public static function normalize(string $json): ?string
    {
        try {
            $value = json_decode($json, false, 128, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }
        return json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: null;
    }

    /**
     * Line diff of two JSON texts (both normalized first).
     *
     * @return array{
     *   hunks: list<list<array{op:string, old:?int, new:?int, text:string}>>,
     *   added:int, removed:int, too_large:bool, identical:bool
     * } op is ' ' (context), '+' or '-'; old/new are 1-based line numbers (null where the line does not exist)
     */
    public static function lines(string $oldJson, string $newJson, int $context = 3): array
    {
        $oldText = self::normalize($oldJson);
        $newText = self::normalize($newJson);
        $empty   = ['hunks' => [], 'added' => 0, 'removed' => 0, 'too_large' => false, 'identical' => true];

        if ($oldText === null || $newText === null) {
            return ['too_large' => true, 'identical' => false] + $empty;
        }
        if ($oldText === $newText) {
            return $empty;
        }

        $a = explode("\n", $oldText);
        $b = explode("\n", $newText);

        // Strip the common head and tail: most edits touch a small part of a large file.
        $head = 0;
        $max  = min(count($a), count($b));
        while ($head < $max && $a[$head] === $b[$head]) {
            $head++;
        }
        $tail = 0;
        while ($tail < $max - $head && $a[count($a) - 1 - $tail] === $b[count($b) - 1 - $tail]) {
            $tail++;
        }
        $midA = array_slice($a, $head, count($a) - $head - $tail);
        $midB = array_slice($b, $head, count($b) - $head - $tail);

        $script = self::myers($midA, $midB);
        if ($script === null) {
            return ['too_large' => true, 'identical' => false] + $empty;
        }

        // Full edit list with line numbers.
        $edits = [];
        for ($k = 0; $k < $head; $k++) {
            $edits[] = ['op' => ' ', 'old' => $k + 1, 'new' => $k + 1, 'text' => $a[$k]];
        }
        $oi = $head;
        $ni = $head;
        $added = $removed = 0;
        foreach ($script as [$op, $text]) {
            if ($op === ' ') {
                $edits[] = ['op' => ' ', 'old' => ++$oi, 'new' => ++$ni, 'text' => $text];
            } elseif ($op === '-') {
                $edits[] = ['op' => '-', 'old' => ++$oi, 'new' => null, 'text' => $text];
                $removed++;
            } else {
                $edits[] = ['op' => '+', 'old' => null, 'new' => ++$ni, 'text' => $text];
                $added++;
            }
        }
        for ($k = 0; $k < $tail; $k++) {
            $edits[] = ['op' => ' ', 'old' => ++$oi, 'new' => ++$ni, 'text' => $a[count($a) - $tail + $k]];
        }

        return ['hunks' => self::hunks($edits, $context), 'added' => $added, 'removed' => $removed, 'too_large' => false, 'identical' => false];
    }

    /**
     * @param list<array{op:string,old:?int,new:?int,text:string}> $edits
     * @return list<list<array{op:string,old:?int,new:?int,text:string}>>
     */
    private static function hunks(array $edits, int $context): array
    {
        $changeIdx = [];
        foreach ($edits as $i => $e) {
            if ($e['op'] !== ' ') {
                $changeIdx[] = $i;
            }
        }

        $hunks = [];
        $start = null;
        $end   = null;
        foreach ($changeIdx as $i) {
            $from = max(0, $i - $context);
            $to   = min(count($edits) - 1, $i + $context);
            if ($start !== null && $from <= $end + 1) {
                $end = max($end, $to);
                continue;
            }
            if ($start !== null) {
                $hunks[] = array_slice($edits, $start, $end - $start + 1);
            }
            $start = $from;
            $end   = $to;
        }
        if ($start !== null) {
            $hunks[] = array_slice($edits, $start, $end - $start + 1);
        }
        return $hunks;
    }

    /**
     * Myers' O(ND) diff. Returns the edit script as [op, text] pairs, or null if it needs more than MAX_EDITS edits.
     *
     * @param list<string> $a
     * @param list<string> $b
     * @return list<array{0:string,1:string}>|null
     */
    private static function myers(array $a, array $b): ?array
    {
        $n = count($a);
        $m = count($b);
        if ($n === 0 && $m === 0) {
            return [];
        }
        $max   = min($n + $m, self::MAX_EDITS);
        $v     = [1 => 0];
        $trace = [];

        for ($d = 0; $d <= $max; $d++) {
            $trace[] = $v;
            for ($k = -$d; $k <= $d; $k += 2) {
                if ($k === -$d || ($k !== $d && ($v[$k - 1] ?? -1) < ($v[$k + 1] ?? -1))) {
                    $x = $v[$k + 1] ?? 0;
                } else {
                    $x = ($v[$k - 1] ?? 0) + 1;
                }
                $y = $x - $k;
                while ($x < $n && $y < $m && $a[$x] === $b[$y]) {
                    $x++;
                    $y++;
                }
                $v[$k] = $x;
                if ($x >= $n && $y >= $m) {
                    return self::backtrack($trace, $a, $b, $d);
                }
            }
        }
        return null;
    }

    /**
     * @param list<array<int,int>> $trace
     * @param list<string> $a
     * @param list<string> $b
     * @return list<array{0:string,1:string}>
     */
    private static function backtrack(array $trace, array $a, array $b, int $d): array
    {
        $x = count($a);
        $y = count($b);
        $script = [];

        for ($step = $d; $step > 0; $step--) {
            $v = $trace[$step];
            $k = $x - $y;
            if ($k === -$step || ($k !== $step && ($v[$k - 1] ?? -1) < ($v[$k + 1] ?? -1))) {
                $prevK = $k + 1;
            } else {
                $prevK = $k - 1;
            }
            $prevX = $v[$prevK] ?? 0;
            $prevY = $prevX - $prevK;

            while ($x > $prevX && $y > $prevY) {
                $script[] = [' ', $a[$x - 1]];
                $x--;
                $y--;
            }
            if ($x === $prevX) {
                $script[] = ['+', $b[$y - 1]];
                $y--;
            } else {
                $script[] = ['-', $a[$x - 1]];
                $x--;
            }
        }
        while ($x > 0 && $y > 0) {
            $script[] = [' ', $a[$x - 1]];
            $x--;
            $y--;
        }

        return array_reverse($script);
    }
}
