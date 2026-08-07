<?php

namespace App\Platform\Identity\Http\Requests;

use App\Platform\Shared\Requests\BaseFormRequest;

class SocialRedirectRequest extends BaseFormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // Optional override of the post-consent return URL; otherwise config('sso.default_redirect_uri').
            'redirect_uri' => ['sometimes', 'string', 'url', 'max:2048'],
        ];
    }
}
