<?php
// src/Validators/AnmeldungValidator.php

declare(strict_types=1);

namespace App\Validators;

class AnmeldungValidator
{
    private array $errors = [];

    /**
     * Validate a new Anmeldung submission
     */
    public function validate(array $data): bool
    {
        $this->errors = [];

        // Required fields
        $this->validateRequired($data, 'formular', 'Formular ist erforderlich');
        $this->validateRequired($data, 'name', 'Name ist erforderlich');
        $this->validateRequired($data, 'email', 'E-Mail ist erforderlich');

        $mail=$data['email'] ?? $data['email1'] ?? $data['Email'] ?? $data['E-mail'] ?? $data['E-Mail'] ?? null;
        // Email format
        if (isset($mail) && !empty($mail)) {
            if (!filter_var($mail, FILTER_VALIDATE_EMAIL)) {
                $this->errors['email'] = 'Ungültige E-Mail-Adresse';
            }
        }

        // Name length
        if (isset($data['name']) && strlen($data['name']) > 255) {
            $this->errors['name'] = 'Name ist zu lang (max. 255 Zeichen)';
        }

        // Status (if provided)
        if (isset($data['status'])) {
            $allowedStatuses = ['neu', 'in_bearbeitung', 'akzeptiert', 'abgelehnt', 'archiviert'];
            if (!in_array($data['status'], $allowedStatuses, true)) {
                $this->errors['status'] = 'Ungültiger Status';
            }
        }

        return empty($this->errors);
    }

    /**
     * Validate required field
     */
    private function validateRequired(array $data, string $field, string $message): void
    {
        if (!isset($data[$field]) || trim((string)$data[$field]) === '') {
            $this->errors[$field] = $message;
        }
    }

    /**
     * Get validation errors
     */
    public function getErrors(): array
    {
        return $this->errors;
    }

    /**
     * Get first error message
     */
    public function getFirstError(): ?string
    {
        return !empty($this->errors) ? reset($this->errors) : null;
    }
}