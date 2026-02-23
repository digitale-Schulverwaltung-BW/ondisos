<?php
// src/Models/Anmeldung.php
// 

declare(strict_types=1);

namespace App\Models;

use DateTimeImmutable;

/**
 * Base Anmeldung - direkt aus DB, kann NULLs haben
 */
readonly class Anmeldung
{
    public function __construct(
        public int $id,
        public string $formular,
        public ?string $formularVersion,
        public ?string $name,
        public ?string $email,
        public string $status,
        public ?array $data,
        public DateTimeImmutable $createdAt,
        public ?DateTimeImmutable $updatedAt,
        public bool $deleted,
        public ?DateTimeImmutable $deletedAt,
        public ?array $pdfConfig = null,
    ) {}

    public static function fromArray(array $row): self
    {
        return new self(
            id: (int)$row['id'],
            formular: $row['formular'],
            formularVersion: $row['formular_version'] ?? null,
            name: $row['name'] ?? null,
            email: $row['email'] ?? $data['email1'] ?? $data['Email'] ?? $data['E-mail'] ?? $data['E-Mail'] ?? null,
            status: $row['status'] ?? 'neu',
            data: isset($row['data']) && $row['data'] !== null
                ? json_decode($row['data'], true)
                : null,
            createdAt: new DateTimeImmutable($row['created_at']),
            updatedAt: isset($row['updated_at'])
                ? new DateTimeImmutable($row['updated_at'])
                : null,
            deleted: (bool)($row['deleted'] ?? false),
            deletedAt: isset($row['deleted_at'])
                ? new DateTimeImmutable($row['deleted_at'])
                : null,
            pdfConfig: isset($row['pdf_config']) && $row['pdf_config'] !== null
                ? json_decode($row['pdf_config'], true)
                : null,
        );
    }

    /**
     * Check if this is a complete/valid registration
     */
    public function isComplete(): bool
    {
        return $this->name !== null 
            && $this->email !== null 
            && filter_var($this->email, FILTER_VALIDATE_EMAIL) !== false;
    }

    /**
     * Convert to Complete Anmeldung (throws if incomplete)
     */
    public function toComplete(): CompleteAnmeldung
    {
        if (!$this->isComplete()) {
            throw new \InvalidArgumentException(
                "Cannot convert incomplete Anmeldung #{$this->id} to CompleteAnmeldung"
            );
        }

        return new CompleteAnmeldung(
            id: $this->id,
            formular: $this->formular,
            formularVersion: $this->formularVersion,
            name: $this->name,
            email: $this->email,
            status: $this->status,
            data: $this->data,
            createdAt: $this->createdAt,
            updatedAt: $this->updatedAt,
            deleted: $this->deleted,
            deletedAt: $this->deletedAt,
        );
    }

    /**
     * Safe getters with defaults
     */
    public function getDisplayName(): string
    {
        return $this->name ?? 'Unbekannt';
    }

    public function getDisplayEmail(): string
    {
        return $this->email ?? '-';
    }

    public function getDisplayVersion(): string
    {
        return $this->formularVersion ?? 'v1.0';
    }
}

/**
 * Complete Anmeldung - garantiert vollständige Daten
 * Nutze diese für Business-Logic, die Name/Email braucht
 */
readonly class CompleteAnmeldung
{
    public function __construct(
        public int $id,
        public string $formular,
        public ?string $formularVersion,
        public string $name,           // ← NOT NULL!
        public string $email,          // ← NOT NULL!
        public string $status,
        public ?array $data,
        public DateTimeImmutable $createdAt,
        public ?DateTimeImmutable $updatedAt,
        public bool $deleted,
        public ?DateTimeImmutable $deletedAt,
    ) {
        // Validation
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new \InvalidArgumentException("Invalid email: $email");
        }
        if (trim($name) === '') {
            throw new \InvalidArgumentException("Name cannot be empty");
        }
    }

    /**
     * Send email notification - safe, weil email garantiert da ist
     */
    public function sendNotification(): void
    {
        // $this->email ist GARANTIERT vorhanden
        mail($this->email, "Anmeldung bestätigt", "...");
    }
}