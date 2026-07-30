<?php

namespace App\Contexts\Commerce\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * A subscription lapsed for good: its grace window closed without a successful renewal, so access is
 * revoked. Carries scalar ids only.
 */
class SubscriptionExpired
{
    use Dispatchable;

    public function __construct(
        public readonly int $subscriptionId,
        public readonly int $userId,
    ) {}
}
