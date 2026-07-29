<?php

namespace App\Domains\Assessment\Support;

use App\Domains\Assessment\Enums\AssignmentState;
use App\Domains\Assessment\Enums\SubmissionStatus;
use App\Domains\Assessment\Models\Assignment;
use App\Domains\Assessment\Models\AssignmentSubmission;
use App\Platform\Shared\Learning\Contracts\AssignmentRequirementPort;
use Illuminate\Database\Eloquent\Collection;

/**
 * Assessment-side implementation of AssignmentRequirementPort. Maps a lesson to its required,
 * published assignments and answers whether a learner has satisfied all of them — so Learning can
 * gate lesson/course completion without importing an Assessment model.
 *
 * "Satisfied" for one required assignment means the learner has a RELEASED grade on their latest
 * attempt that is passing (assignments with a pass mark) or accepted (no pass mark → any released
 * grade counts, but a changes-requested/returned attempt does not).
 */
class AssignmentRequirementAdapter implements AssignmentRequirementPort
{
    public function hasRequired(int $lessonId): bool
    {
        return $this->requiredAssignments($lessonId)->isNotEmpty();
    }

    public function requiredSatisfied(int $lessonId, int $userId): bool
    {
        $required = $this->requiredAssignments($lessonId);

        if ($required->isEmpty()) {
            // No requirement to fail: an empty requirement is vacuously satisfied.
            return true;
        }

        foreach ($required as $assignment) {
            if (! $this->assignmentSatisfied($assignment, $userId)) {
                return false;
            }
        }

        return true;
    }

    /** @return Collection<int, Assignment> */
    private function requiredAssignments(int $lessonId): Collection
    {
        return Assignment::query()
            ->where('lesson_id', $lessonId)
            ->where('required_for_completion', true)
            ->where('publish_state', AssignmentState::Published->value)
            ->get();
    }

    private function assignmentSatisfied(Assignment $assignment, int $userId): bool
    {
        $latest = AssignmentSubmission::query()
            ->where('assignment_id', $assignment->id)
            ->where('user_id', $userId)
            ->with('grade')
            ->orderByDesc('attempt_no')
            ->first();

        if ($latest === null || $latest->status !== SubmissionStatus::Graded) {
            return false;
        }

        $grade = $latest->grade;

        // Only a released grade counts toward completion.
        if ($grade === null || ! $grade->isReleased()) {
            return false;
        }

        // With a pass mark, must have passed; without one, a released grade is acceptance enough.
        if ($assignment->hasPassMark()) {
            return $grade->passed === true;
        }

        return true;
    }
}
