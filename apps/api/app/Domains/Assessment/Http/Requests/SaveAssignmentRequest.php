<?php

namespace App\Domains\Assessment\Http\Requests;

use App\Domains\Assessment\Enums\LatePolicy;
use App\Domains\Assessment\Enums\SubmissionType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Shared create/update rules. `sometimes` everywhere makes PATCH-style updates safe; `title` and
 * `submission_type` are required only on create. Authorization is done in the controller via the
 * policy/gate, never here.
 */
class SaveAssignmentRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        $creating = $this->isMethod('POST');

        return [
            'title' => [$creating ? 'required' : 'sometimes', 'string', 'max:255'],
            'lesson_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'instructions' => ['sometimes', 'nullable', 'array'],

            'submission_type' => [$creating ? 'required' : 'sometimes', Rule::in(SubmissionType::values())],
            'allowed_file_types' => ['sometimes', 'nullable', 'array'],
            'allowed_file_types.*' => ['string', 'max:16'],
            'max_file_size' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:1073741824'],
            'max_files' => ['sometimes', 'integer', 'min:1', 'max:50'],

            'attempt_limit' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:100'],
            'due_at' => ['sometimes', 'nullable', 'date'],
            'late_policy' => ['sometimes', Rule::in(LatePolicy::values())],
            'late_penalty_percent' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:100'],

            'max_grade' => ['sometimes', 'numeric', 'min:0', 'max:100000'],
            'passing_grade' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:100000'],

            'required_for_completion' => ['sometimes', 'boolean'],
        ];
    }
}
