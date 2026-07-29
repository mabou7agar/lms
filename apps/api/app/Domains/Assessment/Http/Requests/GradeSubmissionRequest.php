<?php

namespace App\Domains\Assessment\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * A grade is either a numeric score OR a rubric selection (the service prefers the rubric when
 * present and validates it against the immutable snapshot). `expected_version` drives optimistic
 * concurrency; `private_notes` is grader-only and never echoed to the learner.
 */
class GradeSubmissionRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'score' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:100000'],
            'rubric_result' => ['sometimes', 'nullable', 'array'],
            'rubric_result.*.criterion_public_id' => ['required_with:rubric_result', 'uuid'],
            'rubric_result.*.level_public_id' => ['required_with:rubric_result', 'uuid'],
            'feedback' => ['sometimes', 'nullable', 'string', 'max:20000'],
            'private_notes' => ['sometimes', 'nullable', 'string', 'max:20000'],
            'expected_version' => ['sometimes', 'nullable', 'integer', 'min:0'],
        ];
    }
}
