<?php

namespace App\Platform\Shared\Learning\Data;

/**
 * The learners enrolled in a course who have recorded NO learning-session activity within a window.
 *
 * Scalars only. `userIds` are internal ids (a caller resolves them to boundary-safe refs through
 * the Identity port when it needs display data); `count` is carried alongside so a caller that only
 * wants the headline figure need not count the list itself.
 */
final readonly class InactiveLearners
{
    /** @param list<int> $userIds */
    public function __construct(
        public int $count,
        public array $userIds,
    ) {}

    public static function empty(): self
    {
        return new self(0, []);
    }
}
