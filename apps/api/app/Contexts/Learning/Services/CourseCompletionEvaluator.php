<?php

namespace App\Contexts\Learning\Services;

use App\Contexts\Learning\Models\Enrollment;
use App\Contexts\Learning\Models\LessonVideoProgress;
use App\Contexts\Learning\Support\CompletionPolicy;
use App\Platform\Shared\Assessment\Contracts\AssessmentResultPort;
use App\Platform\Shared\Learning\Contracts\AssignmentRequirementPort;
use App\Platform\Shared\Services\BaseService;

/**
 * Pure, side-effect-free decision: given an enrollment, is its course complete under the course's
 * resolved {@see CompletionPolicy}? It NEVER writes — ProgressService owns persistence and event
 * dispatch; this only answers true/false.
 *
 * It composes ONLY the ENABLED rules of the policy, short-circuiting on the first unmet one:
 *   (a) require_all_lessons          — reuses ProgressService's OWN lesson computation unchanged.
 *   (b) min_watch_percentage         — aggregate watched vs total video duration (satisfied if the
 *                                      denominator is unavailable — see watchRequirementMet()).
 *   (c) require_required_quizzes      — AssessmentResultPort::hasPassedAllRequiredForCourse.
 *   (d) require_final_exam            — AssessmentResultPort::hasPassed(final_exam_assessment_id).
 *   (e) require_required_assignments  — reuses the existing AssignmentRequirementPort per lesson.
 *
 * DEFAULT PRESERVATION: {@see CompletionPolicy::default()} enables only (a) and disables everything
 * else, so isComplete() reduces to exactly ProgressService::allPublishedLessonsComplete() — the same
 * "100% of published lessons" predicate ProgressService used before the policy engine existed.
 */
class CourseCompletionEvaluator extends BaseService
{
    public function __construct(
        private readonly CourseCompletionPolicyResolver $policies,
        private readonly ProgressService $progress,
        private readonly AssessmentResultPort $assessmentResults,
        private readonly AssignmentRequirementPort $assignments,
    ) {}

    public function isComplete(Enrollment $enrollment): bool
    {
        $courseId = $enrollment->courseId();
        $userId = (int) $enrollment->getAttribute('user_id');
        $policy = $this->policies->resolve($courseId);

        // (a) Lesson rule — the pre-existing behaviour, computed by ProgressService itself.
        if ($policy->requireAllLessons && ! $this->progress->allPublishedLessonsComplete($enrollment)) {
            return false;
        }

        // (b) Watch-time rule.
        if ($policy->minWatchPercentage !== null
            && ! $this->watchRequirementMet($enrollment, $policy->minWatchPercentage)) {
            return false;
        }

        // (c) Required quizzes.
        if ($policy->requireRequiredQuizzes
            && ! $this->assessmentResults->hasPassedAllRequiredForCourse($courseId, $userId)) {
            return false;
        }

        // (d) Specific final exam. A misconfigured "require final exam" with no exam id set gates on
        // nothing (there is no specific exam to pass) rather than dead-ending the learner.
        if ($policy->requireFinalExam
            && $policy->finalExamAssessmentId !== null
            && ! $this->assessmentResults->hasPassed($policy->finalExamAssessmentId, $userId)) {
            return false;
        }

        // (e) Required assignments — reuse the per-lesson port across the course's published lessons.
        if ($policy->requireRequiredAssignments
            && ! $this->requiredAssignmentsSatisfied($courseId, $userId)) {
            return false;
        }

        return true;
    }

    /**
     * Aggregate watched vs total known video duration for the enrollment. When the denominator is 0
     * — no video-progress rows, or none carry a server-known duration — the requirement is treated as
     * SATISFIED: this rule only ever ADDS a gate where watch data actually exists, and never blocks a
     * course whose completion basis it cannot measure.
     */
    private function watchRequirementMet(Enrollment $enrollment, int $minPercentage): bool
    {
        $row = LessonVideoProgress::query()
            ->where('enrollment_id', $enrollment->id)
            ->toBase()
            ->selectRaw('coalesce(sum(watched_seconds), 0) as watched')
            ->selectRaw('coalesce(sum(duration_seconds), 0) as total')
            ->first();

        $total = (int) ($row?->total ?? 0);

        if ($total <= 0) {
            return true;
        }

        $watched = (int) ($row?->watched ?? 0);
        $percentage = (int) floor(($watched / $total) * 100);

        return $percentage >= $minPercentage;
    }

    private function requiredAssignmentsSatisfied(int $courseId, int $userId): bool
    {
        foreach ($this->progress->publishedLessonIds($courseId) as $lessonId) {
            $lessonId = (int) $lessonId;

            if ($this->assignments->hasRequired($lessonId)
                && ! $this->assignments->requiredSatisfied($lessonId, $userId)) {
                return false;
            }
        }

        return true;
    }
}
