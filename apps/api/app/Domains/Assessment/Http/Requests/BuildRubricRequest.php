<?php

namespace App\Domains\Assessment\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * A rubric build replaces the assignment's rubric wholesale. Point totals are computed server-side
 * from the levels, so no total is accepted from the client.
 */
class BuildRubricRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'title' => ['sometimes', 'nullable', 'string', 'max:255'],
            'criteria' => ['required', 'array', 'min:1'],
            'criteria.*.title' => ['required', 'string', 'max:255'],
            'criteria.*.description' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'criteria.*.levels' => ['required', 'array', 'min:1'],
            'criteria.*.levels.*.title' => ['required', 'string', 'max:255'],
            'criteria.*.levels.*.description' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'criteria.*.levels.*.points' => ['required', 'numeric', 'min:0', 'max:100000'],
        ];
    }
}
