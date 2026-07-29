<?php

namespace App\Domains\Assessment\Events;

use Illuminate\Foundation\Events\Dispatchable;

/** An assignment became visible/submittable. Scalar-only payload (no Eloquent models). */
class AssignmentPublished
{
    use Dispatchable;

    public function __construct(
        public readonly int $assignmentId,
        public readonly int $courseId,
        public readonly ?int $lessonId,
        public readonly bool $requiredForCompletion,
    ) {}
}
