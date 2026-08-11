<?php

namespace App\Domains\Crm\Http\Requests\Enterprise;

use App\Platform\Shared\Requests\BaseFormRequest;

class AssignManagerRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            // The member (by public id) to promote to manager of this department/team; null clears it.
            'member_id' => ['nullable', 'uuid'],
        ];
    }
}
