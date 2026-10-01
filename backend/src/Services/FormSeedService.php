<?php
declare(strict_types=1);

namespace App\Services;

use App\Forms\ConflictException;
use App\Forms\FormConfigValidator;
use App\Forms\ValidationResult;
use App\Repositories\FormConfigRepository;

/**
 * Adds form configurations (the entries of a forms-config.php) to the current tenant.
 *
 * Existing forms are never touched. Every entry is validated first; an invalid entry is reported and skipped,
 * so a typo in a seed file cannot put a broken form into the database.
 */
class FormSeedService
{
    public const STATUS_SEEDED  = 'seeded';
    public const STATUS_SKIPPED = 'skipped';   // exists already
    public const STATUS_INVALID = 'invalid';
    public const STATUS_IGNORED = 'ignored';   // not a form entry

    public function __construct(
        private readonly FormConfigRepository $configs,
        private readonly FormConfigValidator $validator = new FormConfigValidator(),
    ) {
    }

    /**
     * @param array<int|string, mixed> $forms form key => config entry
     * @return array<string, array{status: string, validation: ValidationResult}> keyed by form key (as text)
     */
    public function seed(array $forms): array
    {
        $out = [];
        foreach ($forms as $key => $entry) {
            $label      = (string)$key;
            $validation = new ValidationResult();

            if (!is_string($key) || !is_array($entry)) {
                $out[$label] = ['status' => self::STATUS_IGNORED, 'validation' => $validation];
                continue;
            }

            $validation = $this->validator->validate($entry);
            if (!$validation->isValid()) {
                $out[$label] = ['status' => self::STATUS_INVALID, 'validation' => $validation];
                continue;
            }

            try {
                $this->configs->insert($key, $entry);
                $out[$label] = ['status' => self::STATUS_SEEDED, 'validation' => $validation];
            } catch (ConflictException) {
                $out[$label] = ['status' => self::STATUS_SKIPPED, 'validation' => $validation];
            } catch (\InvalidArgumentException $e) {
                $validation->addError('form_key', 'Ungültiger Formular-Schlüssel (erlaubt: Kleinbuchstaben, Ziffern, _ und -)');
                $out[$label] = ['status' => self::STATUS_INVALID, 'validation' => $validation];
            }
        }

        return $out;
    }
}
