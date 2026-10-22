<?php
declare(strict_types=1);

namespace App\Services;

use App\Forms\Identifiers;
use App\Forms\ListColumns;
use App\Repositories\FormConfigRepository;
use App\Repositories\FormResourceRepository;
use App\Services\MessageService as M;

/**
 * Extra columns of the registration list for one form: the displayable fields of its published survey.
 *
 * Surveys that exist only as a file in the frontend are unknown to the backend and offer no columns.
 */
class ListColumnService
{
    public function __construct(
        private FormConfigRepository $configs,
        private FormResourceRepository $resources
    ) {}

    /**
     * @return array<string, array{name:string, label:string, type:string, choices:array<string,string>, values:array<string,mixed>, multi:bool, numeric:bool, date:bool}>
     */
    public function catalogFor(string $formKey): array
    {
        $form = Identifiers::isValidFormKey($formKey) ? $this->configs->find($formKey) : null;
        $name = $form['config']['form'] ?? null;
        if (!is_string($name) || !Identifiers::isValidResourceName($name)) {
            return [];
        }
        $row    = $this->resources->find(FormResourceRepository::KIND_SURVEY, $name);
        $survey = $row !== null ? json_decode($row['content'], true) : null;
        if (!is_array($survey)) {
            return [];
        }
        return ListColumns::catalog($survey, [
            'yes'   => M::get('ui.list_columns.yes', 'Ja'),
            'no'    => M::get('ui.list_columns.no', 'Nein'),
            'other' => M::get('ui.list_columns.other', 'Sonstiges'),
        ]);
    }
}
