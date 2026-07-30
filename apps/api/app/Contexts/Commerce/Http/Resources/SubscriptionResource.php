<?php

namespace App\Contexts\Commerce\Http\Resources;

use App\Contexts\Commerce\Models\Subscription;
use App\Contexts\Commerce\Models\SubscriptionPlan;
use App\Platform\Shared\Resources\BaseResource;
use Illuminate\Http\Request;

/**
 * Learner-facing read model for a subscription: its status, the plan, the recurring price (integer
 * minor units), the period / trial / grace / cancellation clocks, and a computed is_active_now flag.
 * Read-only shaping — no business logic, no persistence.
 *
 * @property Subscription $resource
 */
class SubscriptionResource extends BaseResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $subscription = $this->resource;

        return [
            'id' => $subscription->public_id,
            'status' => $subscription->statusEnum()->value,
            'currency' => $subscription->currency(),
            'amount_minor' => $subscription->amountMinor(),
            'current_period_start' => $subscription->currentPeriodStart()?->toIso8601String(),
            'current_period_end' => $subscription->currentPeriodEnd()?->toIso8601String(),
            'trial_ends_at' => $subscription->trialEndsAt()?->toIso8601String(),
            'grace_ends_at' => $subscription->graceEndsAt()?->toIso8601String(),
            'canceled_at' => $subscription->getAttribute('canceled_at')?->toIso8601String(),
            'cancel_at_period_end' => $subscription->cancelAtPeriodEnd(),
            'is_active_now' => $subscription->isActiveNow(),

            'plan' => $this->whenLoaded('plan', fn () => $subscription->plan instanceof SubscriptionPlan ? [
                'id' => $subscription->plan->public_id,
                'name' => $subscription->plan->getAttribute('name'),
                'interval' => $subscription->plan->intervalEnum()->value,
            ] : null),
        ];
    }
}
