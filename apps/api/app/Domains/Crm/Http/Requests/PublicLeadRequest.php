<?php

namespace App\Domains\Crm\Http\Requests;

use App\Domains\Crm\Enums\RequestType;
use App\Platform\Shared\Requests\BaseFormRequest;
use Illuminate\Validation\Rule;

/**
 * Validation for the PUBLIC (guest) enterprise-lead endpoint. Open to everyone (authorize=true);
 * abuse is handled by the route throttle + the `website` honeypot below. Deliberately does NOT
 * accept any tenant/owner/organization_id — a public lead is global and never client-attributed.
 */
class PublicLeadRequest extends BaseFormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'work_email' => ['required', 'email:rfc', 'max:255'],
            'company' => ['required', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:32'],
            'company_size' => ['nullable', 'string', 'max:32'],
            'country' => ['nullable', 'string', 'size:2'],
            'request_type' => ['required', Rule::in(RequestType::values())],
            'message' => ['nullable', 'string', 'max:5000'],
            'source_page' => ['required', 'string', 'max:2048'],

            'utm' => ['nullable', 'array'],
            'utm.source' => ['nullable', 'string', 'max:255'],
            'utm.medium' => ['nullable', 'string', 'max:255'],
            'utm.campaign' => ['nullable', 'string', 'max:255'],
            'utm.term' => ['nullable', 'string', 'max:255'],
            'utm.content' => ['nullable', 'string', 'max:255'],

            'gclid' => ['nullable', 'string', 'max:255'],
            'referrer' => ['nullable', 'string', 'max:2048'],
            'locale' => ['nullable', 'string', 'in:en,ar'],
            'marketing_consent' => ['nullable', 'boolean'],

            // Honeypot: a real user never sees or fills this. Any non-empty value is a bot → 422.
            'website' => ['prohibited'],
        ];
    }
}
