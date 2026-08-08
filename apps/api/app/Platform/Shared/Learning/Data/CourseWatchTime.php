<?php

namespace App\Platform\Shared\Learning\Data;

/**
 * Aggregate video watch-time for a course, as seen from outside the Learning context.
 *
 * Scalars only — no models cross this boundary. `avgWatchedSecondsPerLearner` is watch-time spread
 * across the ENROLLED roster (not just those who pressed play), so `learnerCount` is the enrollment
 * count and is carried too: it lets the caller decide that "no learners" should render as no-data
 * rather than as an average of zero. The average is pre-guarded against div-by-zero here (0 when the
 * roster is empty); the caller still sees `learnerCount === 0` and can surface a sentinel.
 */
final readonly class CourseWatchTime
{
    public function __construct(
        public int $totalWatchedSeconds,
        public int $avgWatchedSecondsPerLearner,
        public int $learnerCount,
    ) {}

    public static function empty(): self
    {
        return new self(0, 0, 0);
    }
}
