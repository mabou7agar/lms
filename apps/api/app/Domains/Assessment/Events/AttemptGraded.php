<?php

namespace App\Domains\Assessment\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * A quiz attempt was scored to a pass/fail outcome (its `passed` is now known). Additive signal for
 * the Assessment notification wiring to congratulate or nudge the learner; carries scalar ids only so
 * any consumer can subscribe without importing an Assessment model.
 *
 * NOT emitted for an attempt still awaiting manual review, nor for an assessment with no pass mark —
 * in both cases the outcome is undecided (`passed` would be null), so `passed` here is always a real
 * boolean. `courseId` is null for a platform-level assessment bank that is not attached to a course.
 */
class AttemptGraded
{
    use Dispatchable;

    public function __construct(
        public readonly int $attemptId,
        public readonly int $learnerUserId,
        public readonly int $assessmentId,
        public readonly ?int $courseId,
        public readonly bool $passed,
        public readonly ?float $score,
    ) {}
}
