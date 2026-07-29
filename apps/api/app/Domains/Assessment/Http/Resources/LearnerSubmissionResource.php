<?php

namespace App\Domains\Assessment\Http\Resources;

use App\Domains\Assessment\Models\AssignmentSubmission;
use App\Domains\Assessment\Models\SubmissionFile;
use App\Platform\Shared\Resources\BaseResource;
use Illuminate\Http\Request;

/**
 * LEARNER view of their own submission. The grade block appears ONLY once released, and even then
 * carries no private_notes — those are computed away here so a grader's internal remarks can never
 * reach the learner regardless of controller wiring.
 *
 * @property AssignmentSubmission $resource
 */
class LearnerSubmissionResource extends BaseResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $submission = $this->resource;
        $grade = $submission->grade;
        $released = $grade !== null && $grade->isReleased();

        return [
            'id' => $submission->public_id,
            'attempt_no' => $submission->attempt_no,
            'status' => $submission->status->value,
            'submitted_at' => $submission->submitted_at?->toIso8601String(),
            'is_late' => $submission->is_late,
            'text_response' => $submission->text_response,
            'external_url' => $submission->external_url,
            'files' => $submission->files->map(fn (SubmissionFile $f): array => [
                'id' => $f->public_id,
                'media_id' => $f->media_public_id,
                'filename' => $f->original_filename,
            ])->values()->all(),
            'rubric_snapshot' => $submission->rubric_snapshot,
            // Only a RELEASED grade is visible, and never the private notes.
            'grade' => $released ? [
                'score' => $grade->score === null ? null : (float) $grade->score,
                'passed' => $grade->passed,
                'feedback' => $grade->feedback,
                'rubric_result' => $grade->rubric_result,
                'released_at' => $grade->released_at?->toIso8601String(),
            ] : null,
        ];
    }
}
