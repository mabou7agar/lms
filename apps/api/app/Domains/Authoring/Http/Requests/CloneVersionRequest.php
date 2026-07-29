<?php

namespace App\Domains\Authoring\Http\Requests;

use App\Platform\Shared\Requests\BaseFormRequest;

class CloneVersionRequest extends BaseFormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'label' => ['nullable', 'string', 'max:200'],
        ];
    }
}
