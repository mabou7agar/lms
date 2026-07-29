<?php

namespace App\Domains\Assessment\Events;

use Illuminate\Foundation\Events\Dispatchable;

/** A learner opened and submitted a NEW attempt after changes were requested / it was returned. */
class AssignmentResubmitted
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
