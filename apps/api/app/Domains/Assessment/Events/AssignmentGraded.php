<?php

namespace App\Domains\Assessment\Events;

use Illuminate\Foundation\Events\Dispatchable;

/** A grader recorded (or re-recorded) a grade. Not yet visible to the learner until released. */
class AssignmentGraded
{
    use Dispatchable;

    public function __construct(
        public readonly int $submissionId,
        public readonly int $assignmentId,
        public readonly int $userId,
        public readonly int $graderId,
        public readonly ?float $score,
        public readonly ?bool $passed,
        public readonly int $version,
    ) {}
}
