<?php

namespace App\Domains\Authoring\Http\Requests;

use App\Platform\Shared\Requests\BaseFormRequest;

class UpdateSectionRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'title' => ['sometimes', 'string', 'max:255'],
            'summary' => ['sometimes', 'nullable', 'string', 'max:1000'],
            // C1 bilingual: optional locale maps; legacy scalars stay synced by HasTranslations.
            'title_i18n' => ['sometimes', 'array'],
            'title_i18n.*' => ['nullable', 'string', 'max:255'],
            'summary_i18n' => ['sometimes', 'array'],
            'summary_i18n.*' => ['nullable', 'string', 'max:1000'],
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
