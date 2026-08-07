<?php

namespace App\Domains\Authoring\Http\Requests;

use App\Platform\Shared\Requests\BaseFormRequest;

/**
 * Ordered list of public_ids (sections or lessons).
 *
 * `expected_version` (C3) is honoured only by the lesson-reorder endpoint, where the parent
 * section is the optimistic-lock unit. Section reorder is course-scoped and server-authoritative,
 * so it ignores the token — supplying it there is harmless.
 */
class ReorderRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'order' => ['required', 'array', 'min:1'],
            'order.*' => ['required', 'string'],
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
