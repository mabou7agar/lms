<?php

namespace App\Platform\Identity\Http\Requests;

use App\Platform\Identity\Enums\ConsentPurpose;
use App\Platform\Shared\Requests\BaseFormRequest;
use Illuminate\Validation\Rule;

class RecordConsentRequest extends BaseFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'purpose' => ['required', Rule::in(ConsentPurpose::values())],
            'granted' => ['required', 'boolean'],
            'version' => ['sometimes', 'nullable', 'string', 'max:32'],
        ];
    }
}
