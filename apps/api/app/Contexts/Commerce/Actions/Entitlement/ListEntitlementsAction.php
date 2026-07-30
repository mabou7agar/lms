<?php

namespace App\Contexts\Commerce\Actions\Entitlement;

use App\Platform\Shared\Actions\BaseAction;
use App\Platform\Shared\Commerce\Contracts\EntitlementPort;

/**
 * Read-side use case for the current user's course entitlements. Delegates to the Shared
 * EntitlementPort so the endpoint reads exactly what any other context would see through the
 * boundary. Returns the de-duplicated list of entitled course ids; no writes.
 */
class ListEntitlementsAction extends BaseAction
{
    public function __construct(
        private readonly EntitlementPort $entitlements,
    ) {}

    /**
     * @return list<int>
     */
    public function execute(int $userId): array
    {
        return $this->entitlements->entitledCourseIds($userId);
    }
}
