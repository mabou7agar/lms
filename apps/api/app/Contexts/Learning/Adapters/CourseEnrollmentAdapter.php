<?php

namespace App\Contexts\Learning\Adapters;

use App\Contexts\Learning\Models\Enrollment;
use App\Platform\Shared\Learning\Contracts\CourseEnrollmentPort;

/**
 * Learning's implementation of CourseEnrollmentPort. Answers enrollment questions for other
 * contexts (Assessment's gradebook roster + submission entitlement) over Learning's own Enrollment
 * model, so those contexts never import it. "Enrolled" means an ACTIVE enrollment; soft-deleted and
 * non-active rows are ignored (the `active()` scope + default SoftDeletes global scope).
 */
class CourseEnrollmentAdapter implements CourseEnrollmentPort
{
    public function isEnrolled(int $courseId, int $userId): bool
    {
        return Enrollment::query()
            ->where('course_id', $courseId)
            ->where('user_id', $userId)
            ->active()
            ->exists();
    }

    public function hasCourseAccess(int $courseId, int $userId): bool
    {
        // Access survives completion: active OR completed enrollment grants it (mirrors the lesson
        // player's grantsAccess() scope), so a learner who finished the course can still take or
        // retake its assessments.
        return Enrollment::query()
            ->where('course_id', $courseId)
            ->where('user_id', $userId)
            ->grantsAccess()
            ->exists();
    }

    /**
     * All actively-enrolled learner user ids for the course, ascending and de-duplicated.
     *
     * @return list<int>
     */
    public function enrolledLearnerIds(int $courseId): array
    {
        /** @var list<int> $ids */
        $ids = Enrollment::query()
            ->where('course_id', $courseId)
            ->active()
            ->orderBy('user_id')
            ->distinct()
            ->pluck('user_id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();

        return $ids;
    }
}
