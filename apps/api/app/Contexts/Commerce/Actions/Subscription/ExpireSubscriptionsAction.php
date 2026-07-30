<?php

namespace App\Contexts\Commerce\Actions\Subscription;

use App\Contexts\Commerce\Enums\SubscriptionChangeType;
use App\Contexts\Commerce\Enums\SubscriptionStatus;
use App\Contexts\Commerce\Events\SubscriptionExpired;
use App\Contexts\Commerce\Models\Subscription;
use App\Contexts\Commerce\Models\SubscriptionChange;
use App\Platform\Shared\Actions\BaseAction;
use App\Platform\Shared\Audit\AuditLogger;
use Illuminate\Support\Carbon;

/**
 * Expire subscriptions whose dunning window has closed: any past_due or grace subscription whose
 * grace_ends_at is in the past lapses to expired and loses access. Batch entry point for the renewal
 * worker (execute()), plus a single-subscription expire() the batch reuses.
 *
 * Idempotent: only past_due/grace subscriptions are touched, each transition is guarded on the
 * current status, and an expired change is recorded with SubscriptionExpired dispatched exactly once.
 */
class ExpireSubscriptionsAction extends BaseAction
{
    public function __construct(
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Expire every subscription past its grace window. Returns the number expired.
     */
    public function execute(): int
    {
        $now = Carbon::now();
        $expired = 0;

        Subscription::query()
            ->whereIn('status', [SubscriptionStatus::PastDue->value, SubscriptionStatus::Grace->value])
            ->whereNotNull('grace_ends_at')
            ->where('grace_ends_at', '<=', $now)
            ->orderBy('id')
            ->chunkById(100, function ($subscriptions) use (&$expired): void {
                foreach ($subscriptions as $subscription) {
                    if ($this->expire($subscription)) {
                        $expired++;
                    }
                }
            });

        return $expired;
    }

    /**
     * Expire a single subscription. Returns true when it transitioned, false when it was not in an
     * expirable state (keeping the operation idempotent).
     */
    public function expire(Subscription $subscription): bool
    {
        $status = $subscription->statusEnum();

        if ($status !== SubscriptionStatus::PastDue && $status !== SubscriptionStatus::Grace) {
            return false;
        }

        $this->transaction(function () use ($subscription): void {
            $subscription->forceFill([
                'status' => SubscriptionStatus::Expired->value,
            ])->save();

            SubscriptionChange::create([
                'subscription_id' => $subscription->getKey(),
                'type' => SubscriptionChangeType::Expired->value,
                'from_plan_id' => $subscription->planId(),
                'amount_minor' => 0,
            ]);
        });

        SubscriptionExpired::dispatch((int) $subscription->getKey(), $subscription->userId());

        $this->audit->log('commerce.subscription.expired', $subscription, [
            'plan_id' => $subscription->planId(),
        ]);

        return true;
    }
}
