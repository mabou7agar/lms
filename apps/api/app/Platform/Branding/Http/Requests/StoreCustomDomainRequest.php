<?php

namespace App\Platform\Branding\Http\Requests;

use App\Platform\Shared\Requests\BaseFormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates a new custom domain. The host is normalized to lowercase and stripped of any
 * scheme/path/port (tolerating a pasted URL) before validation, then shape-checked as a bare
 * hostname. Global uniqueness (one org per host) is enforced with Rule::unique so a duplicate
 * returns the standard 422 envelope rather than a raw DB constraint violation.
 */
class StoreCustomDomainRequest extends BaseFormRequest
{
    protected function prepareForValidation(): void
    {
        $raw = strtolower(trim((string) $this->input('host', '')));
        $raw = preg_replace('#^[a-z]+://#', '', $raw) ?? $raw;
        $raw = explode('/', $raw)[0];
        $raw = explode(':', $raw)[0];

        $this->merge(['host' => $raw]);
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'host' => [
                'required', 'string', 'max:253',
                // Bare hostname: dot-separated a-z0-9(-) labels, at least one dot, no leading/trailing hyphen.
                'regex:/^(?!-)[a-z0-9-]{1,63}(?<!-)(\.(?!-)[a-z0-9-]{1,63}(?<!-))+$/',
                Rule::unique('custom_domains', 'host'),
            ],
            'is_primary' => ['sometimes', 'boolean'],
        ];
    }
}
