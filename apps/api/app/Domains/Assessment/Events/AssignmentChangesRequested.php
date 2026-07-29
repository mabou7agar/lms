<?php

namespace App\Domains\Assessment\Events;

use Illuminate\Foundation\Events\Dispatchable;

/** A grader asked the learner to revise and resubmit. */
class AssignmentChangesRequested
{
    use Dispatchable;

    public function __construct(
        public readonly int $submissionId,
        public readonly int $assignmentId,
        public readonly int $userId,
        public readonly int $graderId,
    ) {}
}
