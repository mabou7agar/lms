<?php

namespace App\Domains\Assessment\Enums;

/**
 * Publish lifecycle of an assignment. Mirrors AssessmentStatus but kept separate so an assignment
 * can evolve its own states without coupling to the quiz lifecycle.
 *
 * A learner may only see and submit against a `published` assignment. `unpublished` hides it again
 * without deleting learner work already recorded.
 */
enum AssignmentState: string
{
    case Draft = 'draft';
    case Published = 'published';
    case Unpublished = 'unpublished';

    public function isPublished(): bool
    {
        return $this === self::Published;
    }

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(fn (self $s) => $s->value, self::cases());
    }
}
