<?php

namespace App\Contexts\Learning\Actions\Enrollment;

use App\Contexts\Learning\Enums\EnrollmentSource;
use App\Contexts\Learning\Exceptions\CourseNotEnrollableException;
use App\Contexts\Learning\Exceptions\CoursePurchaseRequiredException;
use App\Contexts\Learning\Models\Enrollment;
use App\Platform\Shared\Actions\BaseAction;
use App\Platform\Shared\Commerce\Contracts\EntitlementPort;
use App\Platform\Shared\Curriculum\Contracts\CurriculumReadPort;

/**
 * Self-service enrolment into a published course. Delegates the actual grant to
 * GrantEnrollmentAction; enrollability is resolved through CurriculumReadPort by course id.
 *
 * This is the payment-free path, so it must never hand out a course that is on sale. A course sold
 * by an active product is refused here unless the caller already holds an entitlement for it — the
 * paid and company/manager paths go straight to GrantEnrollmentAction and are unaffected. Both
 * checks read Shared ports, so Learning still imports nothing from Commerce.
 */
class EnrollInCourseAction extends BaseAction
{
    public function __construct(
        private readonly GrantEnrollmentAction $grant,
        private readonly CurriculumReadPort $curriculum,
        private readonly EntitlementPort $entitlements,
    ) {}

    public function executeByUserId(int $userId, int $courseId): Enrollment
    {
        if (! $this->curriculum->isCourseEnrollable($courseId)) {
            throw new CourseNotEnrollableException;
        }

        // A purchasable course is only free to enrol into for someone who has already bought it (or
        // holds a seat for it); everyone else must go through checkout.
        if ($this->entitlements->isCoursePurchasable($courseId)
            && ! $this->entitlements->hasCourseEntitlement($userId, $courseId)) {
            throw new CoursePurchaseRequiredException;
        }

        return $this->grant->executeByUserId($userId, $courseId, EnrollmentSource::Free);
    }
}
