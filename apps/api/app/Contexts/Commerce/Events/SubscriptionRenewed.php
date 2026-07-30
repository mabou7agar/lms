<?php

namespace App\Contexts\Commerce\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * A subscription successfully charged and advanced into a new billing period. Carries scalar ids and
 * the charged amount (integer minor units) only, never a cross-context model.
 */
class SubscriptionRenewed
{
    use Dispatchable;

    public function __construct(
        public readonly int $subscriptionId,
        public readonly int $userId,
        public readonly int $planId,
        public readonly int $amountMinor,
        public readonly string $currency,
    ) {}
}
