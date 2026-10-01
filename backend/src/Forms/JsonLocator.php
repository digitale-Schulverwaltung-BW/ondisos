<?php
declare(strict_types=1);

namespace App\Forms;

/**
 * Finds where things are in a JSON text: the position of a syntax error, and the line of every value.
 *
 * json_decode() only says "Syntax error"; an admin pasting a survey needs "line 12, column 5". The validators
 * report problems by path ("pages[0].elements[2].html"); lines() turns such a path into a line number so the
 * report (and the editor) can point at it. Paths use the same notation as SurveyValidator.
 *
 * This is a small recursive-descent reader, not a second JSON implementation: it is only used for locating;
 * what counts as valid JSON is still decided by json_decode() (analyze() reports an error only if it finds one,
 * and the caller combines both).
 */
final class JsonLocator
{
    private const MAX_DEPTH = 64;

    private string $s;
    private int $len;
    private int $i = 0;

    /** @var list<int> byte offsets of line starts */
    private array $lineStarts = [0];

    /** @var array<string,int> path => line (1-based) */
    private array $lines = [];

    /** @var array{line:int,column:int,message:string}|null */
    private ?array $error = null;

    private function __construct(string $json)
    {
        if (str_starts_with($json, "\xEF\xBB\xBF")) {
            $json = substr($json, 3); // byte order mark
        }
        $this->s   = $json;
        $this->len = strlen($json);
        for ($o = 0; $o < $this->len; $o++) {
            if ($json[$o] === "\n") {
                $this->lineStarts[] = $o + 1;
            }
        }
    }

    /**
     * @return array{error: array{line:int,column:int,message:string}|null, lines: array<string,int>}
     */
    public static function analyze(string $json): array
    {
        $p = new self($json);
        try {
            $p->ws();
            $p->value('', 0);
            $p->ws();
            if ($p->i < $p->len) {
                $p->fail('Nach dem JSON-Wert steht noch weiterer Text');
            }
        } catch (\RuntimeException) {
            // $p->error is set
        }

        return ['error' => $p->error, 'lines' => $p->lines];
    }

    private function fail(string $message): never
    {
        $this->error ??= $this->where($this->i, $message);
        throw new \RuntimeException($message);
    }

    /** @return array{line:int,column:int,message:string} */
    private function where(int $offset, string $message): array
    {
        $line = 1;
        foreach ($this->lineStarts as $n => $start) {
            if ($start > $offset) {
                break;
            }
            $line = $n + 1;
        }
        return ['line' => $line, 'column' => $offset - $this->lineStarts[$line - 1] + 1, 'message' => $message];
    }

    private function lineAt(int $offset): int
    {
        return $this->where($offset, '')['line'];
    }

    private function ws(): void
    {
        while ($this->i < $this->len && ($this->s[$this->i] === ' ' || $this->s[$this->i] === "\t" || $this->s[$this->i] === "\n" || $this->s[$this->i] === "\r")) {
            $this->i++;
        }
    }

    private function peek(): string
    {
        return $this->i < $this->len ? $this->s[$this->i] : '';
    }

    private function value(string $path, int $depth): void
    {
        if ($depth > self::MAX_DEPTH) {
            $this->fail('Zu tief verschachtelt');
        }
        $this->lines[$path] = $this->lineAt($this->i);

        $c = $this->peek();
        if ($c === '{') {
            $this->object($path, $depth);
        } elseif ($c === '[') {
            $this->array($path, $depth);
        } elseif ($c === '"') {
            $this->string();
        } elseif ($c === '-' || ($c >= '0' && $c <= '9' && $c !== '')) {
            $this->number();
        } elseif ($c === 't') {
            $this->literal('true');
        } elseif ($c === 'f') {
            $this->literal('false');
        } elseif ($c === 'n') {
            $this->literal('null');
        } elseif ($c === '') {
            $this->fail('Unerwartetes Ende: hier fehlt ein Wert');
        } else {
            $this->fail('Unerwartetes Zeichen »' . $c . '«');
        }
    }

    private function object(string $path, int $depth): void
    {
        $this->i++; // {
        $this->ws();
        if ($this->peek() === '}') {
            $this->i++;
            return;
        }
        while (true) {
            $this->ws();
            if ($this->peek() !== '"') {
                $this->fail($this->peek() === '' ? 'Unerwartetes Ende: Objekt nicht geschlossen' : 'Hier wird ein Name in Anführungszeichen erwartet');
            }
            $keyOffset = $this->i;
            $key       = $this->string();
            $this->ws();
            if ($this->peek() !== ':') {
                $this->fail('Nach dem Namen fehlt ein Doppelpunkt');
            }
            $this->i++;
            $this->ws();
            $childPath = $path === '' ? $key : $path . '.' . $key;
            $this->value($childPath, $depth + 1);
            $this->lines[$childPath] = $this->lineAt($keyOffset);
            $this->ws();
            $c = $this->peek();
            if ($c === ',') {
                $this->i++;
                continue;
            }
            if ($c === '}') {
                $this->i++;
                return;
            }
            $this->fail($c === '' ? 'Unerwartetes Ende: Objekt nicht geschlossen' : 'Hier fehlt ein Komma oder eine schließende Klammer }');
        }
    }

    private function array(string $path, int $depth): void
    {
        $this->i++; // [
        $this->ws();
        if ($this->peek() === ']') {
            $this->i++;
            return;
        }
        for ($index = 0; ; $index++) {
            $this->ws();
            $this->value($path . '[' . $index . ']', $depth + 1);
            $this->ws();
            $c = $this->peek();
            if ($c === ',') {
                $this->i++;
                continue;
            }
            if ($c === ']') {
                $this->i++;
                return;
            }
            $this->fail($c === '' ? 'Unerwartetes Ende: Liste nicht geschlossen' : 'Hier fehlt ein Komma oder eine schließende Klammer ]');
        }
    }

    /** Reads a string at the current position and returns its decoded content. */
    private function string(): string
    {
        $start = $this->i;
        $this->i++; // opening quote
        $out = '';
        while (true) {
            if ($this->i >= $this->len) {
                $this->i = $start;
                $this->fail('Text nicht abgeschlossen (schließendes Anführungszeichen fehlt)');
            }
            $c = $this->s[$this->i];
            if ($c === '"') {
                $this->i++;
                return $out;
            }
            if ($c === "\n" || $c === "\r") {
                $this->fail('Zeilenumbruch im Text: Zeilenumbrüche müssen als \\n geschrieben werden');
            }
            if ($c === '\\') {
                $n = $this->s[$this->i + 1] ?? '';
                if ($n === 'u') {
                    $hex = substr($this->s, $this->i + 2, 4);
                    if (preg_match('/^[0-9a-fA-F]{4}$/D', $hex) !== 1) {
                        $this->fail('Ungültige \\u-Folge');
                    }
                    $out .= '?'; // content is irrelevant for locating, except for object keys with escapes
                    $this->i += 6;
                    continue;
                }
                if (strpos('"\\/bfnrt', $n) === false || $n === '') {
                    $this->i++;
                    $this->fail('Ungültige Escape-Folge im Text');
                }
                $out .= match ($n) { 'n' => "\n", 't' => "\t", 'r' => "\r", 'b' => "\x08", 'f' => "\x0c", default => $n };
                $this->i += 2;
                continue;
            }
            if (ord($c) < 0x20) {
                $this->fail('Steuerzeichen im Text');
            }
            $out .= $c;
            $this->i++;
        }
    }

    private function number(): void
    {
        if (preg_match('/-?(?:0|[1-9][0-9]*)(?:\.[0-9]+)?(?:[eE][+-]?[0-9]+)?/A', $this->s, $m, 0, $this->i) !== 1 || $m[0] === '') {
            $this->fail('Ungültige Zahl');
        }
        $this->i += strlen($m[0]);
    }

    private function literal(string $word): void
    {
        if (substr($this->s, $this->i, strlen($word)) !== $word) {
            $this->fail('Unerwartetes Zeichen »' . $this->s[$this->i] . '« (erwartet: ' . $word . ')');
        }
        $this->i += strlen($word);
    }
}
