<?php

namespace App\Domains\Catalog\Http\Resources\Instructor;

use App\Domains\Catalog\Analytics\Data\CourseAnalyticsReport;
use App\Platform\Shared\Resources\BaseResource;
use Illuminate\Http\Request;

/**
 * Per-course engagement analytics for the instructor portal.
 *
 * Headline figures emit through the same `{value, available, reason?}` envelope as the dashboard
 * overview, so the client renders "no data yet" from a flag rather than special-casing a zero. The
 * drop-off funnel and completion distribution pass through as-is (each lesson already a public ref).
 * No revenue field exists anywhere in this payload.
 *
 * @property CourseAnalyticsReport $resource
 */
class CourseAnalyticsResource extends BaseResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $report = $this->resource;

        return [
            'total_learners' => $report->totalLearners->toArray(),
            'watch_time' => [
                'total_watched_seconds' => $report->totalWatchedSeconds->toArray(),
                'avg_watched_seconds_per_learner' => $report->avgWatchedSecondsPerLearner->toArray(),
            ],
            'inactive_learners' => [
                'count' => $report->inactiveLearners->toArray(),
                'window_days' => $report->inactiveWindowDays,
            ],
            'certificates_issued' => $report->certificatesIssued->toArray(),
            'lesson_drop_off' => $report->lessonDropOff,
            'completion_distribution' => $report->completionDistribution,
        ];
    }
}
