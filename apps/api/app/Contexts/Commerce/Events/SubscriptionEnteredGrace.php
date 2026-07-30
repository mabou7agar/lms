<?php

namespace App\Contexts\Commerce\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * A subscription entered its grace (dunning) window after a failed renewal. Access is retained until
 * the grace clock elapses. Carries scalar ids only.
 */
class SubscriptionEnteredGrace
{
    use Dispatchable;

    public function __construct(
        public readonly int $subscriptionId,
        public readonly int $userId,
    ) {}
}
