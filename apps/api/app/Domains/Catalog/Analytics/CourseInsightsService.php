<?php

namespace App\Domains\Catalog\Analytics;

use App\Domains\Catalog\Analytics\Data\CourseAnalyticsReport;
use App\Platform\Shared\Analytics\Data\MetricValue;
use App\Platform\Shared\Certification\Contracts\CertificateStatusPort;
use App\Platform\Shared\Curriculum\Contracts\CurriculumReadPort;
use App\Platform\Shared\Learning\Contracts\WatchTimePort;

/**
 * Per-course engagement analytics for the instructor portal: watch-time, lesson drop-off, inactive
 * learners and completion distribution — composed from the Learning watch-time port, the Certification
 * status port and the curriculum read port. Catalog reads none of those tables directly.
 *
 * QUERY SHAPE — every figure is a whole-course aggregate whose cost is independent of how many
 * learners are enrolled:
 *   1 query   watch-time SUM (+ 1 roster count)
 *   1 query   drop-off, grouped by lesson
 *   2 queries inactive learners (roster ids vs recently-active ids)
 *   1 query   completion distribution, bucketed
 *   1 query   issued-certificate count
 *   N calls   curriculum ONCE, to label the funnel with public lesson refs (bounded by the course)
 *
 * The curriculum walk is the only per-lesson work and it is bounded by the course's own size, not by
 * the roster — so doubling the learners does not add a single query. Revenue is never surfaced here.
 */
class CourseInsightsService
{
    public function __construct(
        private readonly WatchTimePort $watchTime,
        private readonly CertificateStatusPort $certificates,
        private readonly CurriculumReadPort $curriculum,
    ) {}

    public function forCourse(int $courseId, int $inactiveDays): CourseAnalyticsReport
    {
        $watch = $this->watchTime->watchTimeForCourse($courseId);
        $inactive = $this->watchTime->inactiveLearners($courseId, $inactiveDays);

        // Public lesson refs in curriculum order, resolved ONCE, to label the drop-off funnel with a
        // public id + title rather than the internal lesson id the port returns.
        $refById = [];
        foreach ($this->curriculum->orderedPublishedLessonRefs($courseId) as $ref) {
            $refById[$ref->id] = $ref;
        }

        $dropOff = [];
        foreach ($this->watchTime->lessonDropOff($courseId) as $lessonId => $row) {
            $ref = $refById[$lessonId] ?? null;

            $dropOff[] = [
                'lesson' => $ref === null ? null : ['id' => $ref->publicId, 'title' => $ref->title],
                'started' => $row->startedCount,
                'completed' => $row->completedCount,
                'drop_off' => $row->dropOffCount(),
            ];
        }

        return new CourseAnalyticsReport(
            totalLearners: MetricValue::of($watch->learnerCount),

            // Zero watched seconds is a true zero, not "unknown" — of(), not noData.
            totalWatchedSeconds: MetricValue::of($watch->totalWatchedSeconds),

            // An average over an empty roster is undefined; surface no-data rather than 0s.
            avgWatchedSecondsPerLearner: $watch->learnerCount === 0
                ? MetricValue::noData('No learners enrolled yet.')
                : MetricValue::of($watch->avgWatchedSecondsPerLearner),

            inactiveLearners: MetricValue::of($inactive->count),
            inactiveWindowDays: $inactiveDays,
            certificatesIssued: MetricValue::of($this->certificates->issuedCountForCourse($courseId)),
            lessonDropOff: $dropOff,
            completionDistribution: $this->watchTime->completionDistribution($courseId),
        );
    }
}
