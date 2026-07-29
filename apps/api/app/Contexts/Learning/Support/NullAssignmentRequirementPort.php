<?php

namespace App\Contexts\Learning\Support;

use App\Platform\Shared\Learning\Contracts\AssignmentRequirementPort;

/**
 * Completion-safe default for {@see AssignmentRequirementPort}, active until the Assignment context
 * (Agent C) binds a real implementation. No lesson has a required assignment, so assignments never
 * block completion and existing Learning tests keep passing before Assignment is merged.
 */
final class NullAssignmentRequirementPort implements AssignmentRequirementPort
{
    public function hasRequired(int $lessonId): bool
    {
        return false;
    }

    public function requiredSatisfied(int $lessonId, int $userId): bool
    {
        // Vacuously satisfied: with no required assignment there is nothing to satisfy.
        return true;
    }
}
