<?php

namespace App\Platform\Identity\Http\Requests;

use App\Platform\Shared\Requests\BaseFormRequest;

class RegisterRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'phone' => ['nullable', 'string', 'max:20', 'unique:users,phone'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
            'locale' => ['nullable', 'in:en,ar'],

            // Company accounts register the organization they buy for in the same step. The company
            // name is the only required extra — everything else can be completed later from the
            // manager portal, and demanding a tax id up front would block a legitimate signup.
            'account_type' => ['nullable', 'in:personal,company'],
            'company' => ['required_if:account_type,company', 'array'],
            'company.name' => ['required_if:account_type,company', 'string', 'max:255'],
            'company.size' => ['nullable', 'string', 'max:50'],
            'company.country' => ['nullable', 'string', 'max:100'],
            'company.industry' => ['nullable', 'string', 'max:100'],
            'company.phone' => ['nullable', 'string', 'max:40'],
            'company.tax_id' => ['nullable', 'string', 'max:100'],
            'company.billing_address' => ['nullable', 'string', 'max:1000'],
            'company.website' => ['nullable', 'string', 'max:255'],
        ];
    }
}
