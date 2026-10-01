<?php
declare(strict_types=1);

namespace App\Forms;

/**
 * Outcome of a form-editor operation: validation findings plus, on success, the new version token.
 *
 * ok() is false when validation errors prevented the change; nothing was written in that case.
 * Warnings may be present on success.
 */
final class ServiceResult
{
    public function __construct(
        public readonly ValidationResult $validation,
        public readonly ?string $sha256 = null,
        public readonly ?int $revisionId = null,
    ) {
    }

    public function ok(): bool
    {
        return $this->validation->isValid();
    }

    public static function error(string $path, string $message): self
    {
        $v = new ValidationResult();
        $v->addError($path, $message);
        return new self($v);
    }
}
