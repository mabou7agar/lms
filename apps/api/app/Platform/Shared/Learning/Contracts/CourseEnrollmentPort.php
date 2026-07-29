<?php

namespace App\Platform\Shared\Learning\Contracts;

/**
 * ⚠️ INTEGRATOR (ASSUMED PORT): Assessment needs to know whether a learner may submit against an
 * assignment (i.e. is enrolled in / entitled to the course) without importing Learning's
 * Enrollment model. This narrow contract is what Agent C assumed. Reconcile with Learning's actual
 * enrollment surface — if Learning already exposes an equivalent, bind THAT and delete this file.
 */
interface CourseEnrollmentPort
{
    /** Is this learner actively enrolled in / entitled to the given course? */
    public function isEnrolled(int $courseId, int $userId): bool;

    /**
     * All learner user ids enrolled in the course. Used to build the gradebook roster (so a learner
     * with no submission still shows as "missing"). Returns [] for an unknown course.
     *
     * @return list<int>
     */
    public function enrolledLearnerIds(int $courseId): array;
}
