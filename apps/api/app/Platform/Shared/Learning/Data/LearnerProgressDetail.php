<?php

namespace App\Platform\Shared\Learning\Data;

use App\Platform\Shared\Curriculum\Data\LessonRef;
use App\Platform\Shared\Learning\Contracts\WatchTimePort;

/**
 * One learner's progress through one course, for the instructor drill-down — assembled by Learning
 * (which owns enrollment, lesson-progress and video-progress) and handed across the boundary as a
 * flat, read-only projection.
 *
 * `currentLesson` is the resume pointer (first not-yet-completed lesson in curriculum order) as a
 * Shared LessonRef, or null when the learner has finished (or the course has no lessons). Timestamps
 * are ISO-8601 strings, not Carbon, so no framework type crosses the boundary. Percentages reuse the
 * enrollment's stored figure rather than recomputing, so this can never drift from the completion
 * rule's own number.
 *
 * A null return from {@see WatchTimePort::learnerProgressDetail()}
 * — not a zeroed instance of this — is the signal that the learner is not enrolled.
 */
final readonly class LearnerProgressDetail
{
    public function __construct(
        public ?LessonRef $currentLesson,
        public int $percentComplete,
        public int $watchedSeconds,
        public int $lessonsCompleted,
        public int $lessonsTotal,
        public ?string $lastActivityAt,
        public ?string $startedAt,
        public ?string $completedAt,
    ) {}
}
