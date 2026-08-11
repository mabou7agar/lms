<?php

namespace App\Domains\Crm\Http\Requests\Enterprise;

use App\Platform\Shared\Requests\BaseFormRequest;

class ResizeSeatsRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'seats' => ['required', 'integer', 'min:1', 'max:100000'],
        ];
    }
}
