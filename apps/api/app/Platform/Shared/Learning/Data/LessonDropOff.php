<?php

namespace App\Platform\Shared\Learning\Data;

/**
 * Per-lesson start/complete counts for a course, used to surface drop-off — learners who began a
 * lesson but never finished it.
 *
 * "Started" means a progress row exists in a state other than not-started (in-progress OR completed);
 * "completed" is the completed subset. Drop-off is therefore started minus completed, and is exposed
 * as a derived accessor rather than a stored field so the two can never disagree.
 *
 * `lessonId` is the INTERNAL lesson id: this DTO stays server-side and a reporting caller already
 * holds the curriculum, so it can map the id to a public ref itself.
 */
final readonly class LessonDropOff
{
    public function __construct(
        public int $lessonId,
        public int $startedCount,
        public int $completedCount,
    ) {}

    /** Learners who started the lesson but have not completed it. Never negative. */
    public function dropOffCount(): int
    {
        return max(0, $this->startedCount - $this->completedCount);
    }
}
