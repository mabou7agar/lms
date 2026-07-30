<?php

namespace App\Contexts\Commerce\Http\Resources;

use App\Contexts\Commerce\Models\SubscriptionPlan;
use App\Platform\Shared\Resources\BaseResource;
use Illuminate\Http\Request;

/**
 * Catalogue read model for a subscription plan: its name, billing interval, trial length, active
 * flag, and per-currency prices. All money fields are integer minor units. Read-only shaping — no
 * business logic, no persistence.
 *
 * @property SubscriptionPlan $resource
 */
class SubscriptionPlanResource extends BaseResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $plan = $this->resource;

        return [
            'id' => $plan->public_id,
            'name' => $plan->getAttribute('name'),
            'interval' => $plan->intervalEnum()->value,
            'trial_days' => $plan->trialDays(),
            'is_active' => $plan->isActive(),

            'prices' => $this->whenLoaded('prices', fn () => $plan->prices->map(fn ($price) => [
                'currency' => $price->getAttribute('currency'),
                'amount_minor' => (int) $price->getAttribute('amount_minor'),
                'is_default' => (bool) $price->getAttribute('is_default'),
            ])->values()),
        ];
    }
}
