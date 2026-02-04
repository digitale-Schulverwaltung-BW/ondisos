<?php
// src/Validators/AnmeldungValidator.php

declare(strict_types=1);

namespace App\Validators;

use App\Services\MessageService as M;

class AnmeldungValidator
{
    private array $errors = [];

    /**
     * Allowed MIME types and their corresponding file extensions
     * NOTE: doc/docx excluded due to macro security risks
     */
    private const ALLOWED_MIME_TYPES = [
        'application/pdf' => ['pdf'],
        'image/jpeg' => ['jpg', 'jpeg'],
        'image/png' => ['png'],
        'image/gif' => ['gif'],
        'image/webp' => ['webp'],
        'image/svg+xml' => ['svg'],
        // Office documents excluded by default for security (can contain macros)
        // Uncomment if needed:
        // 'application/msword' => ['doc'],
        // 'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => ['docx'],
    ];

    /**
     * Validate a new Anmeldung submission
     */
    public function validate(array $data): bool
    {
        $this->errors = [];

        // Required fields
        $this->validateRequired($data, 'formular', M::get('validation.required_formular'));
        $this->validateRequired($data, 'name', M::get('validation.required_name'));
        $this->validateRequired($data, 'email', M::get('validation.required_email'));

        $mail=$data['email'] ?? $data['email1'] ?? $data['Email'] ?? $data['E-mail'] ?? $data['E-Mail'] ?? null;
        // Email format
        if (isset($mail) && !empty($mail)) {
            if (!filter_var($mail, FILTER_VALIDATE_EMAIL)) {
                $this->errors['email'] = M::get('validation.invalid_email');
            }
        }

        // Name length
        if (isset($data['name']) && strlen($data['name']) > 255) {
            $this->errors['name'] = M::get('validation.name_too_long');
        }

        // Status (if provided)
        if (isset($data['status'])) {
            $allowedStatuses = ['neu', 'in_bearbeitung', 'akzeptiert', 'abgelehnt', 'archiviert'];
            if (!in_array($data['status'], $allowedStatuses, true)) {
                $this->errors['status'] = M::get('validation.invalid_status');
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

    /**
     * Validate formular name (filter parameter)
     *
     * Ensures the formular name contains only safe characters to prevent
     * SQL injection and other injection attacks.
     *
     * @param string|null $formularName The formular name to validate
     * @throws \InvalidArgumentException If formular name is invalid
     */
    public static function validateFormularName(?string $formularName): void
    {
        // Null or empty is allowed (means no filter)
        if ($formularName === null || $formularName === '') {
            return;
        }

        // Check for reasonable length (max 50 chars)
        if (strlen($formularName) > 50) {
            throw new \InvalidArgumentException(
                M::get('validation.formular_name_too_long', 'Formularname ist zu lang (max. 50 Zeichen)')
            );
        }

        // Only allow alphanumeric chars, underscore, and hyphen
        // This prevents SQL injection, path traversal, and other attacks
        if (!preg_match('/^[a-zA-Z0-9_-]+$/', $formularName)) {
            throw new \InvalidArgumentException(
                M::get('validation.invalid_formular_name', 'Ungültiger Formularname (nur Buchstaben, Zahlen, _ und - erlaubt)')
            );
        }
    }

    /**
     * Validate uploaded file
     *
     * Validates file size, MIME type, and extension to prevent security issues.
     *
     * @param array $file $_FILES array entry
     * @param int|null $maxSize Maximum file size in bytes (null = use default from env)
     * @return array{success: bool, error?: string, mime_type?: string, extension?: string}
     */
    public function validateFile(array $file, ?int $maxSize = null): array
    {
        // Check upload error
        if (!isset($file['error']) || $file['error'] !== UPLOAD_ERR_OK) {
            $error = $file['error'] ?? 'unknown';
            return ['success' => false, 'error' => M::get('validation.upload_failed', "Upload fehlgeschlagen: {$error}")];
        }

        // Validate file size
        $sizeResult = $this->validateFileSize($file, $maxSize);
        if (!$sizeResult['success']) {
            return $sizeResult;
        }

        // Validate MIME type
        $mimeResult = $this->validateMimeType($file);
        if (!$mimeResult['success']) {
            return $mimeResult;
        }

        // Validate extension matches MIME type
        $extResult = $this->validateExtension($file, $mimeResult['mime_type']);
        if (!$extResult['success']) {
            return $extResult;
        }

        return [
            'success' => true,
            'mime_type' => $mimeResult['mime_type'],
            'extension' => $extResult['extension']
        ];
    }

    /**
     * Validate file size
     *
     * @param array $file $_FILES array entry
     * @param int|null $maxSize Maximum size in bytes (null = use env default)
     * @return array{success: bool, error?: string}
     */
    public function validateFileSize(array $file, ?int $maxSize = null): array
    {
        if (!isset($file['size'])) {
            return ['success' => false, 'error' => M::get('validation.file_size_missing', 'Dateigröße fehlt')];
        }

        // Use provided max size or fallback to env (default: 10MB)
        $max = $maxSize ?? (int)(getenv('UPLOAD_MAX_SIZE') ?: 10485760);

        if ($file['size'] > $max) {
            $maxMB = $max / 1048576;
            return [
                'success' => false,
                'error' => M::get('validation.file_too_large', "Datei zu groß (max. {$maxMB} MB)")
            ];
        }

        if ($file['size'] <= 0) {
            return ['success' => false, 'error' => M::get('validation.file_empty', 'Datei ist leer')];
        }

        return ['success' => true];
    }

    /**
     * Validate MIME type using finfo (content-based detection)
     *
     * @param array $file $_FILES array entry
     * @return array{success: bool, error?: string, mime_type?: string}
     */
    public function validateMimeType(array $file): array
    {
        if (!isset($file['tmp_name'])) {
            return ['success' => false, 'error' => M::get('validation.file_tmp_missing', 'Temporäre Datei fehlt')];
        }

        // Initialize finfo for MIME type detection
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo === false) {
            return ['success' => false, 'error' => M::get('validation.finfo_init_failed', 'MIME-Type-Erkennung fehlgeschlagen')];
        }

        // Detect MIME type from file content (not just extension)
        $mimeType = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);

        if ($mimeType === false) {
            return ['success' => false, 'error' => M::get('validation.mime_detection_failed', 'MIME-Type konnte nicht erkannt werden')];
        }

        // Check if MIME type is in whitelist
        if (!isset(self::ALLOWED_MIME_TYPES[$mimeType])) {
            return [
                'success' => false,
                'error' => M::get('validation.mime_type_not_allowed', "Dateityp nicht erlaubt (MIME: {$mimeType})")
            ];
        }

        return ['success' => true, 'mime_type' => $mimeType];
    }

    /**
     * Validate file extension matches MIME type
     *
     * Prevents double extension attacks (e.g., evil.php.jpg)
     *
     * @param array $file $_FILES array entry
     * @param string $mimeType Detected MIME type
     * @return array{success: bool, error?: string, extension?: string}
     */
    public function validateExtension(array $file, string $mimeType): array
    {
        if (!isset($file['name'])) {
            return ['success' => false, 'error' => M::get('validation.filename_missing', 'Dateiname fehlt')];
        }

        // Extract extension (lowercase)
        $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

        if (empty($extension)) {
            return ['success' => false, 'error' => M::get('validation.extension_missing', 'Dateiendung fehlt')];
        }

        // Check if extension matches the detected MIME type
        $allowedExtensions = self::ALLOWED_MIME_TYPES[$mimeType] ?? [];

        if (!in_array($extension, $allowedExtensions, true)) {
            return [
                'success' => false,
                'error' => M::get('validation.extension_mismatch', 'Dateiendung stimmt nicht mit Dateityp überein')
            ];
        }

        return ['success' => true, 'extension' => $extension];
    }

    /**
     * Get allowed MIME types (for external use)
     *
     * @return array<string, array<string>>
     */
    public static function getAllowedMimeTypes(): array
    {
        return self::ALLOWED_MIME_TYPES;
    }
}