<?php
// src/Controllers/AnmeldungController.php

declare(strict_types=1);

namespace App\Controllers;

use App\Config\TenantContext;
use App\Forms\ListColumns;
use App\Services\AnmeldungService;
use App\Services\ListColumnService;

class AnmeldungController
{
    public function __construct(
        private AnmeldungService $service,
        private ?ListColumnService $columns = null
    ) {}

    /**
     * Handle index page request
     */
    public function index(): array
    {
        // Extract and validate request parameters
        $selectedForm = $this->getStringParam('form', '');
        $selectedStatus = $this->getStringParam('status', '');
        $nameSearch = $this->getStringParam('name', '');
        $emailSearch = $this->getStringParam('email', '');
        $sortColumn = $this->getStringParam('sort', 'id');
        $sortDirection = $this->getStringParam('dir', 'DESC');
        $page = $this->getIntParam('page', 1);
        $perPage = $this->getIntParam('perPage', 25);

        // Extra columns from the survey data (only with one form selected)
        $columnCatalog = $selectedForm !== '' && $this->columns !== null ? $this->columns->catalogFor($selectedForm) : [];
        $extraColumns = $this->selectedColumns($selectedForm, $columnCatalog);

        // Filters on choice columns; only for shown columns and values that are choices
        $dataFilters = [];
        $dataConditions = [];
        $requestedFilters = is_array($_GET['f'] ?? null) ? $_GET['f'] : [];
        foreach ($extraColumns as $name) {
            $value = $requestedFilters[$name] ?? null;
            $condition = is_string($value) && $value !== '' ? ListColumns::filterCondition($columnCatalog[$name], $value) : null;
            if ($condition !== null) {
                $dataFilters[$name] = $value;
                $dataConditions[] = $condition;
            }
        }

        // Sorting by an extra column (sort=f:<name>); a column that is not shown falls back to the default
        $dataOrder = null;
        if (str_starts_with($sortColumn, ListColumns::SORT_PREFIX)) {
            $name = substr($sortColumn, strlen(ListColumns::SORT_PREFIX));
            if (in_array($name, $extraColumns, true)) {
                $dataOrder = ListColumns::orderBy($columnCatalog[$name], $sortDirection);
            } else {
                $sortColumn = 'id';
                $sortDirection = 'DESC';
            }
        }

        // Get data through service
        $result = $this->service->getPaginatedAnmeldungen(
            formularFilter: $selectedForm,
            statusFilter: $selectedStatus,
            nameSearch: $nameSearch,
            emailSearch: $emailSearch,
            page: $page,
            perPage: $perPage,
            sortColumn: $sortColumn,
            sortDirection: $sortDirection,
            dataConditions: $dataConditions,
            dataOrder: $dataOrder,
            withData: $extraColumns !== []
        );

        $forms = $this->service->getAvailableForms();
        $allowedPerPage = $this->service->getAllowedPerPageValues();

        return [
            'anmeldungen' => $result['items'],
            'pagination' => $result['pagination'],
            'forms' => $forms,
            'selectedForm' => $selectedForm,
            'selectedStatus' => $selectedStatus,
            'nameSearch' => $nameSearch,
            'emailSearch' => $emailSearch,
            'sortColumn' => $sortColumn,
            'sortDirection' => $sortDirection,
            'allowedPerPage' => $allowedPerPage,
            'columnCatalog' => $columnCatalog,
            'extraColumns' => $extraColumns,
            'dataFilters' => $dataFilters,
        ];
    }

    /**
     * Extra columns to show: from the request (column picker sends cols_set, so "none" is a choice too),
     * otherwise the last choice for this form, kept in the session per tenant and form.
     *
     * @param array<string,array<string,mixed>> $catalog
     * @return list<string>
     */
    private function selectedColumns(string $form, array $catalog): array
    {
        if ($catalog === []) {
            return [];
        }
        $hasSession = session_status() === PHP_SESSION_ACTIVE;
        $tenantId = TenantContext::getTenantId();

        if (isset($_GET['cols_set']) || isset($_GET['cols'])) {
            $selected = ListColumns::select($_GET['cols'] ?? [], $catalog);
            if ($hasSession) {
                $_SESSION['list_columns'][$tenantId][$form] = $selected;
            }
            return $selected;
        }
        return ListColumns::select($hasSession ? ($_SESSION['list_columns'][$tenantId][$form] ?? []) : [], $catalog);
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