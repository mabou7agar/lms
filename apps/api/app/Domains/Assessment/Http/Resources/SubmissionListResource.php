<?php

namespace App\Domains\Assessment\Http\Resources;

use App\Domains\Assessment\Models\AssignmentSubmission;
use App\Platform\Shared\Resources\BaseResource;
use Illuminate\Http\Request;

/**
 * A compact row for the grading QUEUE. No essay body, no files, no private notes — just enough to
 * triage. Carries the grade score only when released.
 *
 * @property AssignmentSubmission $resource
 */
class SubmissionListResource extends BaseResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $submission = $this->resource;
        $grade = $submission->grade;

        return [
            'id' => $submission->public_id,
            'learner_id' => $submission->user_id,
            'attempt_no' => $submission->attempt_no,
            'status' => $submission->status->value,
            'submitted_at' => $submission->submitted_at?->toIso8601String(),
            'is_late' => $submission->is_late,
            'has_grade' => $grade !== null,
            'released' => $grade?->isReleased() ?? false,
            'score' => $grade?->score === null ? null : (float) $grade->score,
        ];
    }
}
