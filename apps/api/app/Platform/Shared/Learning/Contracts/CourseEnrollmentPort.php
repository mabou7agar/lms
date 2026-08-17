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
     * Does this learner have runtime ACCESS to the course — active OR completed enrollment?
     * Course access survives completion (a learner who finished the course may still open its
     * lessons and take/retake its assessments), so attempt-gating must use this, not the stricter
     * active-only isEnrolled().
     */
    public function hasCourseAccess(int $courseId, int $userId): bool;

    /**
     * Did this learner once have access to the course that has since run out?
     *
     * `hasCourseAccess` answers only yes or no, which is enough to refuse but not enough to explain.
     * A learner whose company licence lapsed and a stranger who never enrolled both get false, and
     * they need different things said to them — one renews, the other buys. Consumers ask this to
     * pick which refusal to raise; they never use it to grant anything.
     */
    public function accessWindowElapsed(int $courseId, int $userId): bool;

    /**
     * All learner user ids enrolled in the course. Used to build the gradebook roster (so a learner
     * with no submission still shows as "missing"). Returns [] for an unknown course.
     *
     * @return list<int>
     */
    public function enrolledLearnerIds(int $courseId): array;
}
