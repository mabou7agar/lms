<?php

namespace App\Contexts\Commerce\Adapters;

use App\Contexts\Commerce\Services\EntitlementService;
use App\Platform\Shared\Commerce\Contracts\EntitlementPort;

/**
 * Commerce's implementation of the Shared EntitlementPort. Deliberately thin: it is the seam other
 * contexts (Learning) bind to, and it forwards to EntitlementService, which owns the actual
 * resolution over paid one-off grants and active subscriptions. Only Commerce models + scalars are
 * touched here — no Learning imports cross the boundary.
 */
class EntitlementAdapter implements EntitlementPort
{
    public function __construct(
        private readonly EntitlementService $entitlements,
    ) {}

    public function hasCourseEntitlement(int $userId, int $courseId): bool
    {
        return $this->entitlements->hasCourseEntitlement($userId, $courseId);
    }

    /**
     * @return list<int>
     */
    public function entitledCourseIds(int $userId): array
    {
        return $this->entitlements->entitledCourseIds($userId);
    }
}
