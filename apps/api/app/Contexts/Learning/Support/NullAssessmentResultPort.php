<?php

namespace App\Contexts\Learning\Support;

use App\Platform\Shared\Assessment\Contracts\AssessmentResultPort;

/**
 * Completion-safe default for {@see AssessmentResultPort}, active until the Assessment context binds
 * its real adapter. Reports no required assessments and no passes, so the quiz/final-exam gates are
 * inert: a default-policy course (which never enables them) is entirely unaffected, and a course that
 * does enable them stays IN-PROGRESS rather than falsely completing before Assessment is wired.
 */
final class NullAssessmentResultPort implements AssessmentResultPort
{
    public function hasPassed(int $assessmentId, int $userId): bool
    {
        return false;
    }

    public function hasPassedAllRequiredForCourse(int $courseId, int $userId): bool
    {
        // Vacuously satisfied: with no required assessments there is nothing to pass.
        return true;
    }

    /** @return list<int> */
    public function requiredAssessmentIdsForCourse(int $courseId): array
    {
        return [];
    }
}
