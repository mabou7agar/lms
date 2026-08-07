<?php

namespace App\Domains\Authoring\Http\Requests;

use App\Domains\Authoring\Enums\LessonType;
use App\Platform\Shared\Requests\BaseFormRequest;
use Illuminate\Validation\Rule;

class CreateLessonRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'type' => ['required', Rule::in(LessonType::values())],
            'content' => ['nullable', 'array'],
            'is_preview' => ['nullable', 'boolean'],
            // C1 bilingual: optional title locale map; legacy `title` scalar stays the synced mirror.
            'title_i18n' => ['sometimes', 'array'],
            'title_i18n.*' => ['nullable', 'string', 'max:255'],
        ];
    }
}
