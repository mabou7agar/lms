<?php

namespace App\Contexts\Commerce\Actions\Subscription;

use App\Contexts\Commerce\Enums\SubscriptionChangeType;
use App\Contexts\Commerce\Enums\SubscriptionStatus;
use App\Contexts\Commerce\Exceptions\SubscriptionException;
use App\Contexts\Commerce\Models\Subscription;
use App\Contexts\Commerce\Models\SubscriptionChange;
use App\Platform\Shared\Actions\BaseAction;
use App\Platform\Shared\Audit\AuditLogger;

/**
 * Reactivate a subscription that was canceled but whose paid-through period has not yet elapsed —
 * either undoing a scheduled cancel-at-period-end, or reviving an immediately-canceled subscription
 * while its period is still open. The current period and price are preserved; no charge is taken.
 *
 * Idempotent: a live subscription with no cancellation pending is returned untouched. A subscription
 * that cannot be reactivated (already expired, or canceled with the period elapsed) raises
 * SubscriptionException — the user must subscribe afresh. A reactivation change is recorded.
 */
class ReactivateSubscriptionAction extends BaseAction
{
    public function __construct(
        private readonly AuditLogger $audit,
    ) {}

    public function execute(Subscription $subscription): Subscription
    {
        $status = $subscription->statusEnum();
        $periodEnd = $subscription->currentPeriodEnd();
        $periodStillOpen = $periodEnd === null || $periodEnd->isFuture();

        // Nothing pending: a live subscription with no scheduled cancellation is already reactivated.
        if ($subscription->statusEnum()->grantsAccess() && ! $subscription->cancelAtPeriodEnd()) {
            return $subscription;
        }

        $canRevive = ($status === SubscriptionStatus::Canceled && $periodStillOpen)
            || ($subscription->cancelAtPeriodEnd() && $periodStillOpen);

        if (! $canRevive) {
            throw SubscriptionException::notReactivatable($subscription->public_id);
        }

        // Restore to trialing while the trial is still running, otherwise active.
        $trialEndsAt = $subscription->trialEndsAt();
        $restored = $trialEndsAt !== null && $trialEndsAt->isFuture()
            ? SubscriptionStatus::Trialing
            : SubscriptionStatus::Active;

        $this->transaction(function () use ($subscription, $restored): void {
            $subscription->forceFill([
                'status' => $restored->value,
                'cancel_at_period_end' => false,
                'canceled_at' => null,
            ])->save();

            SubscriptionChange::create([
                'subscription_id' => $subscription->getKey(),
                'type' => SubscriptionChangeType::Reactivation->value,
                'to_plan_id' => $subscription->planId(),
                'amount_minor' => 0,
            ]);
        });

        $this->audit->log('commerce.subscription.reactivated', $subscription, [
            'status' => $restored->value,
        ]);

        return $subscription;
    }
}
