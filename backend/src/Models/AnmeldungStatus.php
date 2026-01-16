<?php
// src/Models/AnmeldungStatus.php

declare(strict_types=1);

namespace App\Models;

enum AnmeldungStatus: string
{
    case NEU = 'neu';
    case GELESEN = 'gelesen';
    case IN_BEARBEITUNG = 'in_bearbeitung';
    case AKZEPTIERT = 'akzeptiert';
    case ABGELEHNT = 'abgelehnt';
    case ARCHIVIERT = 'archiviert';

    /**
     * Get German label
     */
    public function label(): string
    {
        return match($this) {
            self::NEU => 'Neu',
            self::GELESEN => 'Gelesen',
            self::IN_BEARBEITUNG => 'In Bearbeitung',
            self::AKZEPTIERT => 'Akzeptiert',
            self::ABGELEHNT => 'Abgelehnt',
            self::ARCHIVIERT => 'Archiviert',
        };
    }

    /**
     * Get badge CSS class
     */
    public function badgeClass(): string
    {
        return match($this) {
            self::NEU => 'badge bg-primary',
            self::GELESEN => 'badge bg-info',
            self::IN_BEARBEITUNG => 'badge bg-warning text-dark',
            self::AKZEPTIERT => 'badge bg-success',
            self::ABGELEHNT => 'badge bg-danger',
            self::ARCHIVIERT => 'badge bg-secondary',
        };
    }

    /**
     * Safe parsing from string
     */
    public static function tryFromString(?string $value): ?self
    {
        if ($value === null) {
            return null;
        }

        return match($value) {
            'neu' => self::NEU,
            'gelesen' => self::GELESEN,
            'in_bearbeitung' => self::IN_BEARBEITUNG,
            'akzeptiert' => self::AKZEPTIERT,
            'abgelehnt' => self::ABGELEHNT,
            'archiviert' => self::ARCHIVIERT,
            default => null,
        };
    }

    /**
     * Can this status be archived?
     */
    public function canArchive(): bool
    {
        return $this !== self::ARCHIVIERT;
    }

    /**
     * Is this an active status (not archived)?
     */
    public function isActive(): bool
    {
        return $this !== self::ARCHIVIERT;
    }

    /**
     * Get all possible statuses
     * 
     * @return self[]
     */
    public static function all(): array
    {
        return self::cases();
    }

    /**
     * Get active statuses (excluding archived)
     * 
     * @return self[]
     */
    public static function activeStatuses(): array
    {
        return array_filter(
            self::cases(),
            fn(self $status) => $status->isActive()
        );
    }
}