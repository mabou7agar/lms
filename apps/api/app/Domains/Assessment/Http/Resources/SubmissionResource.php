<?php

namespace App\Domains\Assessment\Http\Resources;

use App\Domains\Assessment\Models\AssignmentSubmission;
use App\Domains\Assessment\Models\SubmissionFile;
use App\Platform\Shared\Resources\BaseResource;
use Illuminate\Http\Request;

/**
 * INSTRUCTOR/grader view of a submission. Carries the full grade INCLUDING private_notes and the
 * unreleased score — this resource must NEVER be returned on a learner route.
 *
 * @property AssignmentSubmission $resource
 */
class SubmissionResource extends BaseResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $submission = $this->resource;
        $grade = $submission->grade;

        return [
            'id' => $submission->public_id,
            'assignment_id' => $submission->assignment?->public_id,
            'learner_id' => $submission->user_id,
            'attempt_no' => $submission->attempt_no,
            'status' => $submission->status->value,
            'submitted_at' => $submission->submitted_at?->toIso8601String(),
            'is_late' => $submission->is_late,
            'text_response' => $submission->text_response,
            'external_url' => $submission->external_url,
            'files' => $submission->files->map(fn (SubmissionFile $f): array => [
                'id' => $f->public_id,
                // Public media id — the grader's client requests a signed URL from Media with it.
                'media_id' => $f->media_public_id,
                'filename' => $f->original_filename,
            ])->values()->all(),
            'rubric_snapshot' => $submission->rubric_snapshot,
            'grade' => $grade === null ? null : [
                'score' => $grade->score === null ? null : (float) $grade->score,
                'passed' => $grade->passed,
                'feedback' => $grade->feedback,
                // Grader-only fields:
                'private_notes' => $grade->private_notes,
                'rubric_result' => $grade->rubric_result,
                'version' => $grade->version,
                'released_at' => $grade->released_at?->toIso8601String(),
            ],
        ];
    }
}
