<?php

namespace App\Domains\Crm\Http\Requests\Enterprise;

use App\Platform\Shared\Requests\BaseFormRequest;

class SaveTeamRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            // Optional owning department, referenced by its external public id.
            'department_id' => ['nullable', 'uuid'],
        ];
    }
}
