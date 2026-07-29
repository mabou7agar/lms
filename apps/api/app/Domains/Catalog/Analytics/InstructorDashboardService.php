<?php

namespace App\Domains\Catalog\Analytics;

use App\Domains\Catalog\Enums\CourseStatus;
use App\Domains\Catalog\Models\Course;
use App\Platform\Shared\Analytics\Data\MetricValue;
use App\Platform\Shared\Assessment\Contracts\AssessmentStatsPort;
use App\Platform\Shared\Curriculum\Contracts\CurriculumReadPort;
use App\Platform\Shared\Learning\Contracts\EnrollmentStatsPort;

/**
 * Instructor-scoped dashboard aggregates.
 *
 * Every query is bounded by a course-id set resolved from InstructorScope BEFORE it runs. Nothing
 * here reads a platform-wide aggregate and filters it afterwards — that pattern is how another
 * instructor's learners end up in a total, and it is why this service never touches the global
 * analytics read model.
 *
 * Catalog owns courses and nothing else. Enrollment figures come from Learning through
 * EnrollmentStatsPort and quiz outcomes from Assessment through AssessmentStatsPort; this class
 * reads no table outside its own context. The older InstructorAnalyticsService does query
 * `enrollments` directly, but only because that access predates the boundary and is carried in the
 * Deptrac baseline — it is debt, not a precedent to copy.
 *
 * METRIC DEFINITIONS (authoritative):
 *
 *   total_courses      Courses in scope, any status.
 *   published/draft/archived  Same set, grouped by CourseStatus.
 *   total_learners     DISTINCT users enrolled in any in-scope course. A learner enrolled in three
 *                      of the instructor's courses counts ONCE. Enrollment counts belong at course
 *                      level, where the two cannot diverge misleadingly.
 *   active_learners    Enrollments with status=active AND progress > 0, as distinct users. A STATE,
 *                      not a recency signal — see EnrollmentStatsAdapter.
 *   completion_rate    completed enrollments / total enrollments, whole percent. Enrollment-based:
 *                      one learner completing two courses is two completions.
 *   average_progress   Mean of enrollments.progress_percentage across in-scope enrollments.
 *   assessment_pass_rate  Graded attempts only — see AssessmentStatsPort.
 *   revenue            Unavailable. No instructor revenue backend exists.
 *   at_risk_learners   Unavailable. No risk model exists.
 *
 * Windowing: learner metrics filter on enrollment creation, the pass rate on attempt submission.
 * Course counts are NOT windowed — a course's status is a current fact, not an event in a period.
 */
class InstructorDashboardService
{
    public function __construct(
        private readonly CurriculumReadPort $curriculum,
        private readonly AssessmentStatsPort $assessments,
        private readonly EnrollmentStatsPort $enrollments,
    ) {}

    /**
     * @param  list<int>  $courseIds  already authorization-scoped
     * @return array<string, MetricValue>
     */
    public function overview(array $courseIds, ?string $from = null, ?string $to = null): array
    {
        $statusCounts = $this->courseStatusCounts($courseIds);
        $stats = $this->enrollments->statsForCourses($courseIds, $from, $to);
        $completionRate = $stats->completionRate();

        return [
            'total_courses' => MetricValue::of(count($courseIds)),
            'published_courses' => MetricValue::of($statusCounts[CourseStatus::Published->value] ?? 0),
            'draft_courses' => MetricValue::of($statusCounts[CourseStatus::Draft->value] ?? 0),
            'archived_courses' => MetricValue::of($statusCounts[CourseStatus::Archived->value] ?? 0),
            'total_learners' => MetricValue::of($stats->uniqueLearners),
            'active_learners' => MetricValue::of($stats->activeLearners),

            // A rate over zero enrollments is undefined, not 0% — 0% would read as "nobody is
            // completing", which is a different and false claim.
            'completion_rate' => $completionRate === null
                ? MetricValue::noData('No enrollments yet.')
                : MetricValue::of($completionRate),
            'average_progress' => $stats->enrollments === 0
                ? MetricValue::noData('No enrollments yet.')
                : MetricValue::of($stats->averageProgress),

            'assessment_pass_rate' => $this->passRate($courseIds, $from, $to),

            // Deliberately unavailable. Inventing a number here would be a false statement about an
            // instructor's earnings and about their learners' risk.
            'revenue' => MetricValue::unavailable('Revenue analytics are not available for instructors yet.'),
            'at_risk_learners' => MetricValue::unavailable('At-risk learner detection is not configured.'),
        ];
    }

    /**
     * @param  list<int>  $courseIds
     * @return array<string, int>
     */
    private function courseStatusCounts(array $courseIds): array
    {
        if ($courseIds === []) {
            return [];
        }

        /** @var array<string, int> $counts */
        $counts = Course::query()
            ->whereIn('id', $courseIds)
            ->toBase()
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status')
            ->map(static fn ($v): int => (int) $v)
            ->all();

        return $counts;
    }

    /**
     * Pass rate across every lesson of every in-scope course.
     *
     * Lesson ids come from the curriculum port, attempt outcomes from the Assessment port. Catalog
     * reads neither table, which is what keeps this inside the boundaries.
     *
     * @param  list<int>  $courseIds
     */
    private function passRate(array $courseIds, ?string $from, ?string $to): MetricValue
    {
        $lessonIds = [];

        foreach ($courseIds as $courseId) {
            $tree = $this->curriculum->curriculumTree($courseId, false);

            foreach ($tree['sections'] as $section) {
                foreach ($section['lessons'] as $lesson) {
                    $lessonIds[] = $lesson->id;
                }
            }
        }

        $rate = $this->assessments->passRateForLessons($lessonIds, $from, $to)->passRate();

        return $rate === null
            ? MetricValue::noData('No graded quiz attempts yet.')
            : MetricValue::of($rate);
    }
}
