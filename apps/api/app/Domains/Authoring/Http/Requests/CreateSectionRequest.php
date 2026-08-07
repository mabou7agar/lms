<?php

namespace App\Domains\Authoring\Http\Requests;

use App\Platform\Shared\Requests\BaseFormRequest;

class CreateSectionRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'summary' => ['nullable', 'string', 'max:1000'],
            // C1 bilingual: optional locale maps. The legacy scalar `title`/`summary` stays the
            // English/default-locale mirror (kept in sync by HasTranslations on write), so existing
            // callers that send only the scalar are unaffected.
            'title_i18n' => ['sometimes', 'array'],
            'title_i18n.*' => ['nullable', 'string', 'max:255'],
            'summary_i18n' => ['sometimes', 'array'],
            'summary_i18n.*' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
