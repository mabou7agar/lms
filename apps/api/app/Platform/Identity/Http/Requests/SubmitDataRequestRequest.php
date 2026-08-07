<?php

namespace App\Platform\Identity\Http\Requests;

use App\Platform\Identity\Enums\DataRequestType;
use App\Platform\Shared\Requests\BaseFormRequest;
use Illuminate\Validation\Rule;

class SubmitDataRequestRequest extends BaseFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'type' => ['required', Rule::in(DataRequestType::values())],
            'note' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ];
    }
}
