<?php

namespace App\Domains\Crm\Http\Requests;

use App\Platform\Shared\Requests\BaseFormRequest;

class StoreOpportunityRequest extends BaseFormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'lead' => ['nullable', 'string'],       // Lead public_id (convert-from-lead)
            'company' => ['nullable', 'string'],    // Company public_id
            'pipeline' => ['nullable', 'string'],   // Pipeline public_id
            'amount_minor' => ['nullable', 'integer', 'min:0'],
            'currency' => ['nullable', 'string', 'size:3'],
            'probability' => ['nullable', 'integer', 'between:0,100'],
            'product_ref' => ['nullable', 'string', 'max:255'],
            'expected_close_date' => ['nullable', 'date'],
        ];
    }
}
