<?php

namespace App\Contexts\Commerce\Actions\Subscription;

use App\Contexts\Commerce\Enums\SubscriptionChangeType;
use App\Contexts\Commerce\Enums\SubscriptionStatus;
use App\Contexts\Commerce\Events\SubscriptionCanceled;
use App\Contexts\Commerce\Models\Subscription;
use App\Contexts\Commerce\Models\SubscriptionChange;
use App\Platform\Shared\Actions\BaseAction;
use App\Platform\Shared\Audit\AuditLogger;
use Illuminate\Support\Carbon;

/**
 * Cancel a subscription — either at period end (the default, keeping access until the paid-through
 * date) or immediately.
 *
 * Idempotent: an already-terminal subscription (canceled/expired) is returned untouched.
 *
 *   at period end → cancel_at_period_end is flagged, status is unchanged, a cancellation change is
 *                   recorded, and NO event fires yet. The scheduled cancellation is finalised by the
 *                   renewal worker once the period rolls over (which calls this action immediately).
 *   immediate     → status becomes canceled, canceled_at is stamped, the flag clears, a cancellation
 *                   change is recorded, and SubscriptionCanceled(immediate: true) is dispatched.
 *
 * A cancel-at-period-end request for a subscription whose period has ALREADY elapsed is treated as an
 * immediate cancellation.
 */
class CancelSubscriptionAction extends BaseAction
{
    public function __construct(
        private readonly AuditLogger $audit,
    ) {}

    public function execute(Subscription $subscription, bool $atPeriodEnd = true): Subscription
    {
        $status = $subscription->statusEnum();

        if ($status === SubscriptionStatus::Canceled || $status === SubscriptionStatus::Expired) {
            return $subscription;
        }

        $periodEnd = $subscription->currentPeriodEnd();
        $periodStillOpen = $periodEnd !== null && $periodEnd->isFuture();

        // Schedule for period end only when the period is still open.
        if ($atPeriodEnd && $periodStillOpen) {
            $this->transaction(function () use ($subscription): void {
                $subscription->forceFill(['cancel_at_period_end' => true])->save();

                SubscriptionChange::create([
                    'subscription_id' => $subscription->getKey(),
                    'type' => SubscriptionChangeType::Cancellation->value,
                    'from_plan_id' => $subscription->planId(),
                    'amount_minor' => 0,
                    'note' => 'at_period_end',
                ]);
            });

            $this->audit->log('commerce.subscription.cancel_scheduled', $subscription, [
                'period_end' => $periodEnd->toIso8601String(),
            ]);

            return $subscription;
        }

        $this->transaction(function () use ($subscription): void {
            $subscription->forceFill([
                'status' => SubscriptionStatus::Canceled->value,
                'canceled_at' => Carbon::now(),
                'cancel_at_period_end' => false,
            ])->save();

            SubscriptionChange::create([
                'subscription_id' => $subscription->getKey(),
                'type' => SubscriptionChangeType::Cancellation->value,
                'from_plan_id' => $subscription->planId(),
                'amount_minor' => 0,
                'note' => 'immediate',
            ]);
        });

        SubscriptionCanceled::dispatch((int) $subscription->getKey(), $subscription->userId(), true);

        $this->audit->log('commerce.subscription.canceled', $subscription, [
            'immediate' => true,
        ]);

        return $subscription;
    }
}
