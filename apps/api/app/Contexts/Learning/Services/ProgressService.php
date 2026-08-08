<?php

namespace App\Contexts\Learning\Services;

use App\Contexts\Learning\Enums\EnrollmentStatus;
use App\Contexts\Learning\Enums\LessonProgressStatus;
use App\Contexts\Learning\Models\Enrollment;
use App\Contexts\Learning\Models\LessonProgress;
use App\Platform\Shared\Curriculum\Contracts\CurriculumReadPort;
use App\Platform\Shared\Services\BaseService;
use Illuminate\Support\Collection;

/**
 * Records lesson progress and recomputes section/course percentages. Idempotent per lesson.
 * Curriculum reads (published lesson ids) go through CurriculumReadPort — no Authoring/Catalog
 * model dependency; callers pass lesson/section ids.
 */
class ProgressService extends BaseService
{
    public function __construct(private readonly CurriculumReadPort $curriculum) {}

    /**
     * @return array{progress: LessonProgress, enrollment: Enrollment, just_completed_lesson: bool, just_completed_course: bool}
     */
    public function recordByLessonId(Enrollment $enrollment, int $lessonId, LessonProgressStatus $status, ?int $positionSeconds = null): array
    {
        $progress = LessonProgress::firstOrNew([
            'enrollment_id' => $enrollment->id,
            'lesson_id' => $lessonId,
        ]);

        $wasCompleted = $progress->exists && $progress->status === LessonProgressStatus::Completed;

        $progress->status = $status;
        if ($positionSeconds !== null) {
            $progress->position_seconds = $positionSeconds;
        }
        if ($status === LessonProgressStatus::Completed && $progress->completed_at === null) {
            $progress->completed_at = now();
        }
        $progress->save();

        $justCompletedLesson = ! $wasCompleted && $status === LessonProgressStatus::Completed;

        $justCompletedCourse = $this->recomputeCoursePercentage($enrollment);

        return [
            'progress' => $progress,
            'enrollment' => $enrollment->refresh(),
            'just_completed_lesson' => $justCompletedLesson,
            'just_completed_course' => $justCompletedCourse,
        ];
    }

    public function sectionPercentageById(Enrollment $enrollment, int $sectionId): int
    {
        $lessonIds = collect($this->curriculum->publishedLessonIdsForSection($sectionId));

        return $this->percentage($enrollment, $lessonIds);
    }

    /** Published lesson ids for a course, used for percentage math. */
    public function publishedLessonIds(int $courseId): Collection
    {
        return collect($this->curriculum->publishedLessonIdsForCourse($courseId));
    }

    /**
     * The pre-policy-engine completion predicate, unchanged: 100% of published lessons complete (and
     * at least one published lesson exists). Exposed so CourseCompletionEvaluator can REUSE this exact
     * computation for the require_all_lessons rule rather than reimplement it — keeping the default
     * decision byte-identical to what recomputeCoursePercentage() decided before.
     */
    public function allPublishedLessonsComplete(Enrollment $enrollment): bool
    {
        $lessonIds = $this->publishedLessonIds($enrollment->course_id);

        if ($lessonIds->isEmpty()) {
            return false;
        }

        return $this->percentage($enrollment, $lessonIds)
            >= (int) config('learning.progress.completion_percentage', 100);
    }

    private function recomputeCoursePercentage(Enrollment $enrollment): bool
    {
        $lessonIds = $this->publishedLessonIds($enrollment->course_id);
        $percentage = $this->percentage($enrollment, $lessonIds);

        $justCompleted = false;

        $enrollment->progress_percentage = $percentage;

        // The completion DECISION now delegates to the policy engine. For a course with no policy row
        // the resolved policy is CompletionPolicy::default(), which reduces isComplete() to exactly the
        // former "percentage >= 100 && has published lessons" test — so default behaviour is preserved.
        // progress_percentage above is computed identically and is unaffected by the policy.
        // Resolved lazily to avoid a constructor cycle (the evaluator depends on ProgressService).
        if ($enrollment->status !== EnrollmentStatus::Completed
            && app(CourseCompletionEvaluator::class)->isComplete($enrollment)) {
            $enrollment->status = EnrollmentStatus::Completed;
            $enrollment->completed_at = now();
            $justCompleted = true;
        }

        $enrollment->save();

        return $justCompleted;
    }

    private function percentage(Enrollment $enrollment, Collection $lessonIds): int
    {
        if ($lessonIds->isEmpty()) {
            return 0;
        }

        $completed = LessonProgress::query()
            ->where('enrollment_id', $enrollment->id)
            ->whereIn('lesson_id', $lessonIds)
            ->where('status', LessonProgressStatus::Completed->value)
            ->count();

        return (int) floor(($completed / $lessonIds->count()) * 100);
    }
}
