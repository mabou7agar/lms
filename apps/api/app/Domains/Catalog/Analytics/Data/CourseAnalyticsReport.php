<?php

namespace App\Domains\Catalog\Analytics\Data;

use App\Platform\Shared\Analytics\Data\MetricValue;

/**
 * Instructor-facing engagement analytics for one course: watch-time, the per-lesson drop-off funnel,
 * inactive-learner count, completion distribution and issued-certificate count.
 *
 * Headline figures ride the same {@see MetricValue} envelope the rest of the instructor dashboard
 * uses, so "no learners yet" renders as no-data rather than a misleading zero average. The funnel and
 * distribution are plain shaped arrays (a lesson is a PUBLIC ref, never an internal id). Revenue is
 * deliberately absent — the instructor analytics surface never carries it.
 */
final readonly class CourseAnalyticsReport
{
    /**
     * @param  list<array{lesson: array{id: string, title: string}|null, started: int, completed: int, drop_off: int}>  $lessonDropOff
     * @param  array<string, int>  $completionDistribution
     */
    public function __construct(
        public MetricValue $totalLearners,
        public MetricValue $totalWatchedSeconds,
        public MetricValue $avgWatchedSecondsPerLearner,
        public MetricValue $inactiveLearners,
        public int $inactiveWindowDays,
        public MetricValue $certificatesIssued,
        public array $lessonDropOff,
        public array $completionDistribution,
    ) {}
}
