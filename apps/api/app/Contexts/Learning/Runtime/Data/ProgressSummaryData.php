<?php

namespace App\Contexts\Learning\Runtime\Data;

/**
 * A learner's progress summary for one course: coarse counts plus the resume pointer. Read-only.
 */
final readonly class ProgressSummaryData
{
    public function __construct(
        public string $coursePublicId,
        public string $enrollmentStatus,
        public int $progressPercentage,
        public int $totalLessons,
        public int $completedLessons,
        public bool $courseCompleted,
        public ?string $resumeLessonPublicId,
    ) {}
}
