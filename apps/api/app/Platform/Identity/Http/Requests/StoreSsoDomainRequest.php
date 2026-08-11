<?php

namespace App\Platform\Identity\Http\Requests;

use App\Platform\Identity\Enums\SsoDomainMode;
use App\Platform\Shared\Requests\BaseFormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates a new SSO domain mapping. The domain is normalized to lowercase and stripped of any
 * scheme/path/leading "@" before validation, then shape-checked as a bare registrable domain.
 * Global uniqueness (one org per domain) is enforced in the action against the standard error
 * envelope so a friendly 422 is returned rather than a raw DB constraint violation.
 */
class StoreSsoDomainRequest extends BaseFormRequest
{
    protected function prepareForValidation(): void
    {
        $raw = (string) $this->input('domain', '');
        $normalized = strtolower(trim($raw));
        // Tolerate paste of an email, a URL, or a leading @ — keep only the host portion.
        $normalized = preg_replace('#^[a-z]+://#', '', $normalized) ?? $normalized;
        $normalized = ltrim($normalized, '@');
        $normalized = explode('/', $normalized)[0];
        if (str_contains($normalized, '@')) {
            $normalized = (string) substr(strrchr($normalized, '@') ?: '', 1);
        }

        $this->merge(['domain' => $normalized]);
    }

    public function rules(): array
    {
        return [
            'domain' => [
                'required', 'string', 'max:253',
                // Bare registrable domain: labels of a-z0-9(-), at least one dot, no leading/trailing hyphen.
                'regex:/^(?!-)[a-z0-9-]{1,63}(?<!-)(\.(?!-)[a-z0-9-]{1,63}(?<!-))+$/',
            ],
            'mode' => ['required', 'string', Rule::in(SsoDomainMode::values())],
        ];
    }
}
