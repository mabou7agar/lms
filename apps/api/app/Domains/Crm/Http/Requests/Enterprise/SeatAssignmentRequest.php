<?php

namespace App\Domains\Crm\Http\Requests\Enterprise;

use App\Platform\Shared\Requests\BaseFormRequest;

class SeatAssignmentRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            // The member (by public id) to assign/release a seat for.
            'member_id' => ['required', 'uuid'],
        ];
    }
}
