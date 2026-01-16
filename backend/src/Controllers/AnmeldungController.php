<?php
// src/Controllers/AnmeldungController.php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\AnmeldungService;

class AnmeldungController
{
    public function __construct(
        private AnmeldungService $service
    ) {}

    /**
     * Handle index page request
     */
    public function index(): array
    {
        // Extract and validate request parameters
        $selectedForm = $this->getStringParam('form', '');
        $selectedStatus = $this->getStringParam('status', '');
        $page = $this->getIntParam('page', 1);
        $perPage = $this->getIntParam('perPage', 25);

        // Get data through service
        $result = $this->service->getPaginatedAnmeldungen(
            formularFilter: $selectedForm,
            statusFilter: $selectedStatus,
            page: $page,
            perPage: $perPage
        );

        $forms = $this->service->getAvailableForms();
        $allowedPerPage = $this->service->getAllowedPerPageValues();

        return [
            'anmeldungen' => $result['items'],
            'pagination' => $result['pagination'],
            'forms' => $forms,
            'selectedForm' => $selectedForm,
            'selectedStatus' => $selectedStatus,
            'allowedPerPage' => $allowedPerPage
        ];
    }

    /**
     * Get string parameter from GET request
     */
    private function getStringParam(string $key, string $default = ''): string
    {
        return isset($_GET[$key]) ? trim((string)$_GET[$key]) : $default;
    }

    /**
     * Get integer parameter from GET request
     */
    private function getIntParam(string $key, int $default = 0): int
    {
        return isset($_GET[$key]) ? (int)$_GET[$key] : $default;
    }

    /**
     * Build query string for pagination/filtering
     */
    public function buildQueryString(array $params): string
    {
        return http_build_query(array_filter($params, fn($v) => $v !== '' && $v !== null));
    }
}