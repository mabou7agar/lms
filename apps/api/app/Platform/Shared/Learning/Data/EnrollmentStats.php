<?php

namespace App\Platform\Shared\Learning\Data;

/**
 * Enrollment aggregates for a set of courses, as seen from outside the Learning context.
 *
 * Scalars only — no models cross this boundary. Rates are deliberately NOT computed here: the
 * caller decides how to present an empty denominator, and this DTO must not have to know that
 * "no enrollments" should render as unavailable rather than 0%.
 */
final readonly class EnrollmentStats
{
    public function __construct(
        public int $enrollments,
        public int $completions,
        public int $uniqueLearners,
        public int $activeLearners,
        public int $averageProgress,
    ) {}

    public static function empty(): self
    {
        return new self(0, 0, 0, 0, 0);
    }

    /** Whole-percentage completion rate, or null when nothing is enrolled. */
    public function completionRate(): ?int
    {
        if ($this->enrollments === 0) {
            return null;
        }

        return (int) round(($this->completions / $this->enrollments) * 100);
    }
}
