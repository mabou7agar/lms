<?php

namespace App\Domains\Assessment\Events;

use Illuminate\Foundation\Events\Dispatchable;

/** A learner submitted an assignment attempt for the first time. */
class AssignmentSubmitted
{
    use Dispatchable;

    public function __construct(
        public readonly int $submissionId,
        public readonly int $assignmentId,
        public readonly int $userId,
        public readonly int $attemptNo,
        public readonly bool $isLate,
    ) {}
}
