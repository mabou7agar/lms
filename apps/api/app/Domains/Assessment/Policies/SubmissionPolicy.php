<?php

namespace App\Domains\Assessment\Policies;

use App\Domains\Assessment\Models\Assignment;
use App\Domains\Assessment\Models\AssignmentSubmission;
use App\Platform\Identity\Contracts\Actor;
use App\Platform\Shared\Policies\BasePolicy;
use Illuminate\Support\Facades\Gate;

/**
 * A submission is visible to its OWNING learner and to a grader who manages the parent assignment's
 * course. Private grader notes are never exposed to the learner — that is enforced by using a
 * separate learner resource, but `viewPrivateNotes` gives services a single predicate to check.
 */
class SubmissionPolicy extends BasePolicy
{
    public function before(mixed $user, string $ability): ?bool
    {
        if ($user instanceof Actor && $user->hasRole('super_admin')) {
            return true;
        }

        return null;
    }

    /** The learner who owns it, or a grader of its course. */
    public function view(Actor $user, AssignmentSubmission $submission): bool
    {
        return $this->owns($user, $submission) || $this->grades($user, $submission);
    }

    /** Only the owning learner may write draft content. */
    public function update(Actor $user, AssignmentSubmission $submission): bool
    {
        return $this->owns($user, $submission);
    }

    /** Only a grader may grade / request changes / release. */
    public function grade(Actor $user, AssignmentSubmission $submission): bool
    {
        return $this->grades($user, $submission);
    }

    /** Grader-only: private notes and unreleased scores. */
    public function viewPrivateNotes(Actor $user, AssignmentSubmission $submission): bool
    {
        return $this->grades($user, $submission);
    }

    private function owns(Actor $user, AssignmentSubmission $submission): bool
    {
        return (int) $submission->user_id === $user->actorId();
    }

    private function grades(Actor $user, AssignmentSubmission $submission): bool
    {
        $assignment = $submission->assignment;

        return $assignment instanceof Assignment
            && Gate::forUser($user)->allows('assignment.manage-assignment', $assignment);
    }
}
