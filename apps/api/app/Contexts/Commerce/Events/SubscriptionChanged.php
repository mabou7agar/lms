<?php

namespace App\Contexts\Commerce\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * A subscription's plan changed (an immediate upgrade). Carries scalar ids and the change type
 * string only, never a cross-context model. A downgrade is recorded as a pending change and applied
 * at renewal, so it does not emit this event until it takes effect.
 */
class SubscriptionChanged
{
    use Dispatchable;

    public function __construct(
        public readonly int $subscriptionId,
        public readonly int $userId,
        public readonly int $fromPlanId,
        public readonly int $toPlanId,
        public readonly string $changeType,
    ) {}
}
