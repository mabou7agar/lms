<?php

namespace App\Contexts\Commerce\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * A subscription was created (trialing or active). Carries scalar ids only so listeners in other
 * bounded contexts can react (e.g. surface an entitlement) without importing Commerce models.
 */
class SubscriptionCreated
{
    use Dispatchable;

    public function __construct(
        public readonly int $subscriptionId,
        public readonly int $userId,
        public readonly int $planId,
    ) {}
}
