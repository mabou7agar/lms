<?php

namespace App\Platform\Shared\Learning\Contracts;

use App\Platform\Shared\Learning\Data\CourseWatchTime;
use App\Platform\Shared\Learning\Data\InactiveLearners;
use App\Platform\Shared\Learning\Data\LearnerProgressDetail;
use App\Platform\Shared\Learning\Data\LessonDropOff;

/**
 * Watch-time and progress read model for reporting surfaces that live OUTSIDE the Learning context
 * (the instructor portal). DECLARED here in Shared, IMPLEMENTED by Learning — which owns the
 * enrollment, lesson-progress and video-progress tables — so no other context reads them directly.
 *
 * Every method is BATCH-shaped: each answers a whole-course (or whole-learner) question with a
 * bounded set of aggregate queries, never a query per lesson or per learner. Returns are scalar DTOs
 * / arrays, never Eloquent, and guard their own div-by-zero. Course ids passed here MUST already be
 * authorization-scoped by the caller — this port performs no ownership check.
 */
interface WatchTimePort
{
    /** Total and per-enrolled-learner average watch-time for a course. Empty totals when none. */
    public function watchTimeForCourse(int $courseId): CourseWatchTime;

    /**
     * Per-lesson start/complete counts for a course, keyed by internal lesson id and ordered by
     * curriculum position where the curriculum is available. Every published lesson appears (with
     * zero counts when untouched), so a caller can render the whole funnel without a per-lesson read.
     *
     * @return array<int, LessonDropOff>
     */
    public function lessonDropOff(int $courseId): array;

    /**
     * Learners enrolled in the course with no learning-session activity in the last `$sinceDays`.
     * A recency signal (last_activity_at), distinct from the enrollment-state "active" figure.
     */
    public function inactiveLearners(int $courseId, int $sinceDays): InactiveLearners;

    /**
     * Distribution of enrolled learners across completion buckets, keyed by a fixed set of labels
     * ('0', '1-25', '26-50', '51-75', '76-99', '100'). Every bucket is present (zero when empty), in
     * ascending order, so the caller never has to reconcile a missing key.
     *
     * @return array<string, int>
     */
    public function completionDistribution(int $courseId): array;

    /**
     * One learner's progress detail for a course, or null when the learner is not enrolled (the
     * caller turns that null into a 404). Computed with a single bounded query set — no per-lesson
     * reads — reusing the enrollment's stored percentage rather than recomputing it.
     */
    public function learnerProgressDetail(int $courseId, int $userId): ?LearnerProgressDetail;
}
