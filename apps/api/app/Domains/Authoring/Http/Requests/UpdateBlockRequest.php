<?php

namespace App\Domains\Authoring\Http\Requests;

use App\Domains\Authoring\Http\Requests\Concerns\ValidatesBlockPayload;
use App\Domains\Authoring\Support\SupportedBlockTypes;
use App\Platform\Shared\Requests\BaseFormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Validation\Rule;

/**
 * C5 - Edit a content block. Every field is optional (partial update). `expected_version` carries
 * the C3 optimistic-lock token: absent = backward-compatible last-write-wins; present + stale =>
 * the same 409 { error:"stale_write", current_version } contract as sections/lessons.
 */
class UpdateBlockRequest extends BaseFormRequest
{
    use ValidatesBlockPayload;

    public function rules(): array
    {
        return [
            'type' => ['sometimes', Rule::in(SupportedBlockTypes::values())],
            'content_i18n' => ['sometimes', 'array'],
            'config' => ['sometimes', 'nullable', 'array'],
            'expected_version' => ['sometimes', 'integer', 'min:0'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(fn (Validator $v) => $this->validateLocalizedPayload($v));
    }

    /** The optimistic-lock version the caller believes it is editing, or null when omitted. */
    public function expectedVersion(): ?int
    {
        $value = $this->validated()['expected_version'] ?? null;

        return $value === null ? null : (int) $value;
    }
}
