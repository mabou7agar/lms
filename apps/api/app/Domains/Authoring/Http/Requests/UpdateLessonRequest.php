<?php

namespace App\Domains\Authoring\Http\Requests;

use App\Domains\Authoring\Enums\LessonType;
use App\Platform\Shared\Requests\BaseFormRequest;
use Illuminate\Validation\Rule;

class UpdateLessonRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'title' => ['sometimes', 'string', 'max:255'],
            'type' => ['sometimes', Rule::in(LessonType::values())],
            'content' => ['sometimes', 'nullable', 'array'],
            // C1 bilingual: optional title locale map; legacy `title` scalar stays the synced mirror.
            'title_i18n' => ['sometimes', 'array'],
            'title_i18n.*' => ['nullable', 'string', 'max:255'],
            // C3 optimistic lock. Optional: absent = backward-compatible last-write-wins.
            'expected_version' => ['sometimes', 'integer', 'min:0'],
        ];
    }

    /** The optimistic-lock version the caller believes it is editing, or null when omitted. */
    public function expectedVersion(): ?int
    {
        $value = $this->validated()['expected_version'] ?? null;

        return $value === null ? null : (int) $value;
    }
}
