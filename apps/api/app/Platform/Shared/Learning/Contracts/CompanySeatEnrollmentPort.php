<?php

namespace App\Platform\Shared\Learning\Contracts;

/**
 * Handing an employee a seat from their company's purchase, and taking it back.
 *
 * Separate from EnrollmentGrantPort because company-seat access is not the learner's own: it carries
 * an expiry from the purchase, and it can be revoked. The one rule every implementation must hold to
 * is that access the learner obtained some OTHER way is untouchable here — if a learner already
 * bought the course themselves, granting them a company seat must not overwrite their enrollment
 * with an expiring one, and revoking the seat must not take their own purchase away.
 *
 * DECLARED in Shared, IMPLEMENTED by Learning, CONSUMED by Commerce. Only scalars cross.
 */
interface CompanySeatEnrollmentPort
{
    /**
     * Idempotently give the user access to the course from a company seat.
     *
     * @param  string|null  $accessEndsAt  ISO-8601 instant the access lapses, or null for open-ended.
     *                                     Refreshed on re-grant so extending a purchase extends access.
     */
    public function grantCompanySeat(int $courseId, int $userId, ?string $accessEndsAt): void;

    /**
     * Withdraw company-seat access to the course. A no-op unless the learner's enrollment actually
     * came from a company seat, so a personal purchase is never revoked by a manager's action.
     */
    public function revokeCompanySeat(int $courseId, int $userId): void;

    /**
     * Has the learner begun the course — any recorded progress at all? Drives the `before_start`
     * reassignment policy.
     */
    public function hasStartedCourse(int $courseId, int $userId): bool;

    /**
     * The learner's progress through the course as a whole percentage, 0 when they have no
     * enrollment. Drives the `before_progress_threshold` reassignment policy.
     */
    public function courseProgressPercentage(int $courseId, int $userId): int;

    /**
     * The highest progress the learner has reached across the given courses — the figure a
     * reassignment check needs for a bundle, where any one started course should block a recall.
     *
     * @param  list<int>  $courseIds
     */
    public function highestProgressPercentage(array $courseIds, int $userId): int;
}
