<?php

namespace App\Contexts\Commerce\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * An organization subscription was created (trialing or active) with a provisioned seat pool.
 * Carries scalar ids only so listeners in other bounded contexts can react without importing
 * Commerce models. The individual-user path continues to raise SubscriptionCreated; this is the
 * organization-subscriber counterpart.
 */
class OrganizationSubscriptionCreated
{
    use Dispatchable;

    public function __construct(
        public readonly int $subscriptionId,
        public readonly int $organizationId,
        public readonly int $planId,
        public readonly int $seats,
        public readonly int $seatPoolId,
    ) {}
}
