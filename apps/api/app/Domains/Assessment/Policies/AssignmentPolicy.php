<?php

namespace App\Domains\Assessment\Policies;

use App\Domains\Assessment\Models\Assignment;
use App\Platform\Identity\Contracts\Actor;
use App\Platform\Shared\Policies\BasePolicy;
use Illuminate\Support\Facades\Gate;

/**
 * Instructor authorization for assignments. Every ability resolves through the single
 * `assignment.manage-assignment` gate (registered in AssessmentServiceProvider), which delegates
 * course ownership to CourseAccessPort — an instructor who may manage a course's content may manage
 * its assignments, and nothing else. Learner submission is authorized separately (enrollment), not
 * here.
 */
class AssignmentPolicy extends BasePolicy
{
    public function before(mixed $user, string $ability): ?bool
    {
        if ($user instanceof Actor && $user->hasRole('super_admin')) {
            return true;
        }

        return null;
    }

    public function view(Actor $user, Assignment $assignment): bool
    {
        return $this->manages($user, $assignment);
    }

    public function update(Actor $user, Assignment $assignment): bool
    {
        return $this->manages($user, $assignment);
    }

    public function delete(Actor $user, Assignment $assignment): bool
    {
        return $this->manages($user, $assignment);
    }

    public function grade(Actor $user, Assignment $assignment): bool
    {
        return $this->manages($user, $assignment);
    }

    private function manages(Actor $user, Assignment $assignment): bool
    {
        return Gate::forUser($user)->allows('assignment.manage-assignment', $assignment);
    }
}
