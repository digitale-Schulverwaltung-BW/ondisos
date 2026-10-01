<?php
declare(strict_types=1);

namespace App\Cli;

/**
 * Minimal argument parser for the backend CLI scripts: --name=value, --flag, positional arguments
 * ("-" counts as positional: it means STDIN). Everything after a lone "--" is positional.
 */
final class CliArgs
{
    /** @var array<string, string|true> */
    public readonly array $options;

    /** @var list<string> */
    public readonly array $positional;

    /**
     * @param list<string> $args arguments without the script name
     */
    public function __construct(array $args)
    {
        $options    = [];
        $positional = [];
        $rest       = false;

        foreach ($args as $arg) {
            if ($rest || $arg === '-' || !str_starts_with($arg, '--')) {
                $positional[] = $arg;
            } elseif ($arg === '--') {
                $rest = true;
            } elseif (str_contains($arg, '=')) {
                [$name, $value] = explode('=', substr($arg, 2), 2);
                $options[$name] = $value;
            } else {
                $options[substr($arg, 2)] = true;
            }
        }

        $this->options    = $options;
        $this->positional = $positional;
    }

    public function flag(string $name): bool
    {
        return ($this->options[$name] ?? false) === true;
    }

    /** Value of --name=value; null if absent or given as a bare flag. */
    public function value(string $name): ?string
    {
        $v = $this->options[$name] ?? null;
        return is_string($v) && $v !== '' ? $v : null;
    }

    /**
     * Names of options that are not in $known (typos like --overwite).
     *
     * @param list<string> $known
     * @return list<string>
     */
    public function unknownOptions(array $known): array
    {
        return array_values(array_diff(array_keys($this->options), $known));
    }
}
