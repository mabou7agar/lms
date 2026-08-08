<?php

namespace App\Domains\Catalog\Http\Resources\Instructor;

use App\Domains\Catalog\Analytics\Data\LearnerProgressReport;
use App\Platform\Shared\Resources\BaseResource;
use Illuminate\Http\Request;

/**
 * The instructor drill-down for one enrolled learner: current lesson, completion, watch-time,
 * per-required-assessment outcome and certificate status.
 *
 * PII-safe by construction. The learner is exposed as public id + display name only — the report
 * carries a boundary-safe UserRef, so the internal user id, email and account internals never reach
 * here. The current lesson is a PUBLIC lesson id + title (from a Shared LessonRef), not an internal
 * id. Timestamps are already ISO-8601 strings from the Learning port.
 *
 * @property LearnerProgressReport $resource
 */
class InstructorLearnerProgressResource extends BaseResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $report = $this->resource;
        $detail = $report->detail;
        $current = $detail->currentLesson;

        return [
            'student' => [
                'id' => $report->student->publicId,
                'name' => $report->student->name,
            ],
            'current_lesson' => $current === null ? null : [
                'id' => $current->publicId,
                'title' => $current->title,
                'type' => $current->type,
            ],
            'percent_complete' => $detail->percentComplete,
            'watched_seconds' => $detail->watchedSeconds,
            'lessons_completed' => $detail->lessonsCompleted,
            'lessons_total' => $detail->lessonsTotal,
            'last_activity_at' => $detail->lastActivityAt,
            'started_at' => $detail->startedAt,
            'completed_at' => $detail->completedAt,
            'assessments' => [
                'required' => $report->requiredAssessments,
                'passed' => $report->passedAssessments,
                'all_required_passed' => $report->allRequiredAssessmentsPassed,
            ],
            'certificate' => [
                'issued' => $report->hasCertificate,
            ],
        ];
    }
}
