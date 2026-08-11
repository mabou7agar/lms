<?php

namespace App\Platform\Identity\Http\Requests;

use App\Platform\Identity\Enums\ApiScope;
use App\Platform\Shared\Requests\BaseFormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates a developer API-key creation request. Scope-catalog membership is enforced HERE
 * (only values in {@see ApiScope} are accepted, so a key can never be granted a scope outside the
 * catalog); the "≤ creator permissions" rule and organization presence are enforced in the
 * controller where the acting user is available.
 */
class CreateApiKeyRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'scopes' => ['required', 'array', 'min:1'],
            'scopes.*' => ['string', Rule::in(ApiScope::values())],
            'expires_at' => ['sometimes', 'nullable', 'date', 'after:now'],
        ];
    }
}
