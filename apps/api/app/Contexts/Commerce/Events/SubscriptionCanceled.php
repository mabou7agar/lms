<?php

namespace App\Contexts\Commerce\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * A subscription was canceled. $immediate distinguishes an immediate cancellation (access revoked
 * now) from the finalisation of a scheduled cancel-at-period-end. Carries scalar ids only.
 */
class SubscriptionCanceled
{
    use Dispatchable;

    public function __construct(
        public readonly int $subscriptionId,
        public readonly int $userId,
        public readonly bool $immediate,
    ) {}
}
