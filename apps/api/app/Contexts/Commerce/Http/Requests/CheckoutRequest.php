<?php

namespace App\Contexts\Commerce\Http\Requests;

use App\Platform\Shared\Requests\BaseFormRequest;

/**
 * Billing identity confirmed at checkout. Every field is optional: the buyer type and the owning
 * organization come from the cart (server-side), and an individual buying with the details already
 * on their account should not be forced to retype them.
 */
class CheckoutRequest extends BaseFormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'billing_name' => ['nullable', 'string', 'max:255'],
            'billing_email' => ['nullable', 'email', 'max:255'],
            'billing_phone' => ['nullable', 'string', 'max:40'],
            'billing_country' => ['nullable', 'string', 'max:100'],
            'billing_tax_id' => ['nullable', 'string', 'max:100'],
            'billing_address' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
