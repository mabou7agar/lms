<?php

namespace App\Domains\Assessment\Actions\Assessment;

use App\Domains\Assessment\Enums\AssessmentStatus;
use App\Domains\Assessment\Models\Assessment;
use App\Platform\Shared\Actions\BaseAction;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The write side of the existing `lessons.assessment_id` reference (added by this domain's own
 * migration): points a lesson at this assessment, or clears that reference.
 *
 * Authoring owns the Lesson model, so — like the admin surfaces that already READ `lessons`
 * (DB::table('lessons')) — the reference is written through the query builder rather than by
 * importing an Authoring model, keeping the domain boundary intact. Scope is enforced in SQL: a
 * lesson may only be pointed at an assessment in its OWN course, and an archived assessment may
 * never be attached to anything new, mirroring LessonAssessmentAdapter's attachability guard so the
 * panel cannot bypass a rule the API enforces.
 */
class AttachAssessmentToLessonAction extends BaseAction
{
    /** @throws ValidationException */
    public function attach(Assessment $assessment, int $lessonId): void
    {
        if ($assessment->course_id === null) {
            throw ValidationException::withMessages([
                'lesson_id' => 'A platform-level assessment belongs to no course, so it has no lessons to place it in.',
            ]);
        }

        if ($assessment->status === AssessmentStatus::Archived) {
            throw ValidationException::withMessages([
                'lesson_id' => 'An archived assessment cannot be attached to a lesson.',
            ]);
        }

        if ($this->lessonCourseId($lessonId) !== (int) $assessment->course_id) {
            throw ValidationException::withMessages([
                'lesson_id' => 'That lesson does not belong to this assessment\'s course.',
            ]);
        }

        $this->transaction(function () use ($assessment, $lessonId): void {
            DB::table('lessons')
                ->where('id', $lessonId)
                ->update(['assessment_id' => $assessment->id, 'updated_at' => now()]);
        });
    }

    /** Clears the reference, and only if the lesson currently points at THIS assessment. */
    public function detach(Assessment $assessment, int $lessonId): void
    {
        $this->transaction(function () use ($assessment, $lessonId): void {
            DB::table('lessons')
                ->where('id', $lessonId)
                ->where('assessment_id', $assessment->id)
                ->update(['assessment_id' => null, 'updated_at' => now()]);
        });
    }

    /** The course a lesson belongs to (via its section), or null if the lesson does not exist. */
    private function lessonCourseId(int $lessonId): ?int
    {
        $courseId = DB::table('lessons')
            ->join('course_sections', 'lessons.section_id', '=', 'course_sections.id')
            ->where('lessons.id', $lessonId)
            ->value('course_sections.course_id');

        return $courseId === null ? null : (int) $courseId;
    }
}
