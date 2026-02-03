<?php
// src/Services/AnmeldungService.php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\AnmeldungRepository;
use App\Validators\AnmeldungValidator;

class AnmeldungService
{
    private const ALLOWED_PER_PAGE = [10, 25, 50, 100];
    private const DEFAULT_PER_PAGE = 25;

    public function __construct(
        private AnmeldungRepository $repository
    ) {}

    /**
     * Get paginated anmeldungen with validation
     * 
     * @return array{
     *   items: \App\Models\Anmeldung[],
     *   pagination: array{
     *     page: int,
     *     perPage: int,
     *     totalPages: int,
     *     totalItems: int
     *   }
     * }
     */
    public function getPaginatedAnmeldungen(
        ?string $formularFilter = null,
        ?string $statusFilter = null,
        int $page = 1,
        int $perPage = self::DEFAULT_PER_PAGE
    ): array {
        // Validate and sanitize inputs
        $perPage = $this->validatePerPage($perPage);
        $page = max(1, $page);
        $offset = ($page - 1) * $perPage;

        // Sanitize filters
        $formularFilter = $formularFilter !== '' ? $formularFilter : null;
        $statusFilter = $statusFilter !== '' ? $statusFilter : null;

        // Validate formular filter to prevent SQL injection
        AnmeldungValidator::validateFormularName($formularFilter);

        // Fetch data
        $result = $this->repository->findPaginated(
            formularFilter: $formularFilter,
            statusFilter: $statusFilter,
            limit: $perPage,
            offset: $offset
        );

        $totalPages = max(1, (int)ceil($result['total'] / $perPage));

        return [
            'items' => $result['items'],
            'pagination' => [
                'page' => $page,
                'perPage' => $perPage,
                'totalPages' => $totalPages,
                'totalItems' => $result['total']
            ]
        ];
    }

    /**
     * Get all available forms
     * 
     * @return string[]
     */
    public function getAvailableForms(): array
    {
        return $this->repository->getAllFormNames();
    }

    /**
     * Get allowed per-page values
     * 
     * @return int[]
     */
    public function getAllowedPerPageValues(): array
    {
        return self::ALLOWED_PER_PAGE;
    }

    /**
     * Validate perPage value
     */
    private function validatePerPage(int $perPage): int
    {
        return in_array($perPage, self::ALLOWED_PER_PAGE, true)
            ? $perPage
            : self::DEFAULT_PER_PAGE;
    }
}