<?php

namespace App\Domains\Assessment\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * A grade became visible to the learner. This is the signal Learning listens for to recalculate
 * lesson/course completion (a required assignment is only "satisfied" once its grade is released).
 */
class AssignmentGradeReleased
{
    use Dispatchable;

    public function __construct(
        public readonly int $submissionId,
        public readonly int $assignmentId,
        public readonly int $courseId,
        public readonly ?int $lessonId,
        public readonly int $userId,
        public readonly ?bool $passed,
    ) {}
}
