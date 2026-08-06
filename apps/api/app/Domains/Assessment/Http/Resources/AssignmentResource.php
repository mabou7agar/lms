<?php

namespace App\Domains\Assessment\Http\Resources;

use App\Domains\Assessment\Models\Assignment;
use App\Platform\Shared\Resources\BaseResource;
use Illuminate\Http\Request;

/**
 * INSTRUCTOR view of an assignment. Includes authoring settings and the rubric (when loaded).
 *
 * @property Assignment $resource
 */
class AssignmentResource extends BaseResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $rubric = $this->resource->rubric();

        return [
            'id' => $this->resource->public_id,
            'title' => $this->resource->localized('title'),
            'lesson_id' => $this->resource->lesson_id,
            'instructions' => $this->resource->instructions,
            'submission_type' => $this->resource->submission_type->value,
            'publish_state' => $this->resource->publish_state->value,
            'required_for_completion' => $this->resource->required_for_completion,
            'settings' => [
                'allowed_file_types' => $this->resource->allowed_file_types,
                'max_file_size' => $this->resource->max_file_size,
                'max_files' => $this->resource->max_files,
                'attempt_limit' => $this->resource->attempt_limit,
                'due_at' => $this->resource->due_at?->toIso8601String(),
                'late_policy' => $this->resource->late_policy->value,
                'late_penalty_percent' => $this->resource->late_penalty_percent,
                'max_grade' => (float) $this->resource->max_grade,
                'passing_grade' => $this->resource->passing_grade === null ? null : (float) $this->resource->passing_grade,
            ],
            'rubric' => $rubric === null ? null : (new RubricResource($rubric))->toArray($request),
        ];
    }
}
