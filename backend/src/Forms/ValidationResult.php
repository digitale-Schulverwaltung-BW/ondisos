<?php
declare(strict_types=1);

namespace App\Forms;

/**
 * Collects the findings of a validation run.
 *
 * Errors block saving/publishing; warnings are shown to the admin but do not.
 * Each finding carries a path (e.g. "pages[0].elements[2].html" or "pdf.token_lifetime")
 * so the UI can point at the offending spot.
 */
final class ValidationResult
{
    /** @var list<array{path:string,message:string}> */
    private array $errors = [];

    /** @var list<array{path:string,message:string}> */
    private array $warnings = [];

    public function addError(string $path, string $message): void
    {
        $this->errors[] = ['path' => $path, 'message' => $message];
    }

    public function addWarning(string $path, string $message): void
    {
        $this->warnings[] = ['path' => $path, 'message' => $message];
    }

    public function merge(self $other): void
    {
        $this->errors   = [...$this->errors, ...$other->errors];
        $this->warnings = [...$this->warnings, ...$other->warnings];
    }

    public function isValid(): bool
    {
        return $this->errors === [];
    }

    /** @return list<array{path:string,message:string}> */
    public function errors(): array
    {
        return $this->errors;
    }

    /** @return list<array{path:string,message:string}> */
    public function warnings(): array
    {
        return $this->warnings;
    }

    /** True if an error was reported for exactly this path. */
    public function hasErrorAt(string $path): bool
    {
        foreach ($this->errors as $e) {
            if ($e['path'] === $path) {
                return true;
            }
        }
        return false;
    }
}
