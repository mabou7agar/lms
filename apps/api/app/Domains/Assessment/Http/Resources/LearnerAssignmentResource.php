<?php

namespace App\Domains\Assessment\Http\Resources;

use App\Domains\Assessment\Models\Assignment;
use App\Platform\Shared\Resources\BaseResource;
use Illuminate\Http\Request;

/**
 * LEARNER view of an assignment: what to hand in and by when. Never carries authoring-only fields
 * such as which lesson wiring or internal grading knobs beyond what a learner needs.
 *
 * @property Assignment $resource
 */
class LearnerAssignmentResource extends BaseResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $rubric = $this->resource->rubric();

        return [
            'id' => $this->resource->public_id,
            'title' => $this->resource->localized('title'),
            'instructions' => $this->resource->instructions,
            'submission_type' => $this->resource->submission_type->value,
            'allowed_file_types' => $this->resource->allowed_file_types,
            'max_file_size' => $this->resource->max_file_size,
            'max_files' => $this->resource->max_files,
            'attempt_limit' => $this->resource->attempt_limit,
            'due_at' => $this->resource->due_at?->toIso8601String(),
            'late_policy' => $this->resource->late_policy->value,
            'max_grade' => (float) $this->resource->max_grade,
            'passing_grade' => $this->resource->passing_grade === null ? null : (float) $this->resource->passing_grade,
            // The rubric standard is shown to the learner; their SCORE against it is not (that lives
            // on the released grade only).
            'rubric' => $rubric === null ? null : (new RubricResource($rubric))->toArray($request),
        ];
    }
}
