<?php

namespace App\Platform\Shared\Learning\Contracts;

use App\Contexts\Learning\Support\NullAssignmentRequirementPort;

/**
 * Cross-context port DECLARED by Learning and IMPLEMENTED by the Assignment context (Agent C).
 *
 * Answers the two — and only two — questions the lesson/course completion rule has about
 * assignments: does this lesson gate completion on an assignment at all, and (if so) has the
 * learner satisfied every such assignment. Grading, submissions, rubrics and attempt state never
 * appear here; if the completion rule ever appears to need one of those, that is a signal the
 * feature belongs in the Assignment context, not a signal to widen this interface.
 *
 * A boundary-safe port: scalar ids only, no Eloquent, no throwing. Until the Assignment context
 * binds a real implementation, {@see NullAssignmentRequirementPort}
 * is the default and is completion-safe (no required assignment, so nothing blocks).
 */
interface AssignmentRequirementPort
{
    /** True if the lesson has at least one assignment that is required-for-completion. */
    public function hasRequired(int $lessonId): bool;

    /** True if every required assignment in the lesson is satisfied (accepted/passing) for the learner. */
    public function requiredSatisfied(int $lessonId, int $userId): bool;
}
