<?php

namespace App\Domains\Authoring\Http\Requests;

use App\Domains\Authoring\Http\Requests\Concerns\ValidatesBlockPayload;
use App\Domains\Authoring\Support\SupportedBlockTypes;
use App\Platform\Shared\Requests\BaseFormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Validation\Rule;

/**
 * C5 - Create a content block under a lesson. `type` is constrained to the runtime-supported set
 * (SupportedBlockTypes), and the localized `content_i18n` payload is validated per type by the
 * shared after-hook so it can never be an uncontrolled blob.
 */
class CreateBlockRequest extends BaseFormRequest
{
    use ValidatesBlockPayload;

    public function rules(): array
    {
        return [
            'type' => ['required', Rule::in(SupportedBlockTypes::values())],
            'content_i18n' => ['sometimes', 'array'],
            'config' => ['sometimes', 'nullable', 'array'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(fn (Validator $v) => $this->validateLocalizedPayload($v));
    }
}
