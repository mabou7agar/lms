<?php

namespace App\Contexts\Commerce\Models;

use App\Contexts\Commerce\Enums\SubscriptionChangeType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An immutable audit row for one subscription transition (created, renewal, upgrade, downgrade,
 * cancellation, reactivation, entered_grace, expired). from_plan_id/to_plan_id capture a plan
 * switch; amount_minor is the money moved for the transition (proration on an upgrade, the charge on
 * a renewal, 0 otherwise) in integer minor units.
 *
 * @property-read Subscription|null $subscription
 * @property int $id
 * @property int $subscription_id
 * @property SubscriptionChangeType $type
 * @property int|null $from_plan_id
 * @property int|null $to_plan_id
 * @property int $amount_minor
 * @property string|null $note
 */
class SubscriptionChange extends Model
{
    protected $fillable = [
        'subscription_id',
        'type',
        'from_plan_id',
        'to_plan_id',
        'amount_minor',
        'note',
    ];

    protected function casts(): array
    {
        return [
            'type' => SubscriptionChangeType::class,
            'amount_minor' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Subscription, $this>
     */
    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    /**
     * @return BelongsTo<SubscriptionPlan, $this>
     */
    public function fromPlan(): BelongsTo
    {
        return $this->belongsTo(SubscriptionPlan::class, 'from_plan_id');
    }

    /**
     * @return BelongsTo<SubscriptionPlan, $this>
     */
    public function toPlan(): BelongsTo
    {
        return $this->belongsTo(SubscriptionPlan::class, 'to_plan_id');
    }

    /** Typed type accessor for PHPStan-clean reads. */
    public function typeEnum(): SubscriptionChangeType
    {
        $type = $this->getAttribute('type');

        return $type instanceof SubscriptionChangeType
            ? $type
            : SubscriptionChangeType::from((string) $type);
    }
}
