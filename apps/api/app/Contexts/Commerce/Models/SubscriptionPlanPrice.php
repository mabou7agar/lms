<?php

namespace App\Contexts\Commerce\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A per-currency price for a subscription plan (one row per currency, one flagged default). Money
 * is integer minor units only; the plan's recurring charge amount is read from here, never derived.
 *
 * @property-read SubscriptionPlan|null $plan
 * @property int $id
 * @property int $plan_id
 * @property string $currency
 * @property int $amount_minor
 * @property bool $is_default
 */
class SubscriptionPlanPrice extends Model
{
    protected $fillable = [
        'plan_id',
        'currency',
        'amount_minor',
        'is_default',
    ];

    protected function casts(): array
    {
        return [
            'amount_minor' => 'integer',
            'is_default' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<SubscriptionPlan, $this>
     */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(SubscriptionPlan::class, 'plan_id');
    }

    public function amountMinor(): int
    {
        return (int) $this->getAttribute('amount_minor');
    }

    public function isDefault(): bool
    {
        return (bool) $this->getAttribute('is_default');
    }
}
