<?php

namespace App\Contexts\Commerce\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A claim taken by a renewal worker on one (subscription, billing-period start) pair. Creation is
 * guarded by the table's UNIQUE (subscription_id, period_start) index: a second concurrent worker
 * that attempts the same claim hits a UniqueConstraintViolationException and backs off without
 * charging, so a billing period is charged at most once even on gateways that ignore the charge
 * idempotency key. A claim is deleted only when its charge fails, so a genuine later retry for the
 * same still-due period can re-attempt.
 *
 * @property-read Subscription|null $subscription
 * @property int $id
 * @property int $subscription_id
 * @property Carbon $period_start
 * @property Carbon|null $created_at
 */
class SubscriptionRenewalClaim extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'subscription_id',
        'period_start',
    ];

    protected function casts(): array
    {
        return [
            'period_start' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Subscription, $this>
     */
    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }
}
