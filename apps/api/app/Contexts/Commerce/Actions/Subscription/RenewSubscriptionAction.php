<?php

namespace App\Contexts\Commerce\Actions\Subscription;

use App\Contexts\Commerce\Contracts\PaymentGateway;
use App\Contexts\Commerce\Enums\SubscriptionChangeType;
use App\Contexts\Commerce\Enums\SubscriptionStatus;
use App\Contexts\Commerce\Events\SubscriptionRenewed;
use App\Contexts\Commerce\Models\Subscription;
use App\Contexts\Commerce\Models\SubscriptionChange;
use App\Contexts\Commerce\Models\SubscriptionPlan;
use App\Contexts\Commerce\Payments\Data\ChargeRequest;
use App\Platform\Shared\Actions\BaseAction;
use App\Platform\Shared\Audit\AuditLogger;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Charge a due subscription for its next period and advance it.
 *
 * A subscription is "due" once current_period_end has passed. This action is the single per-period
 * charge point and is idempotent: a subscription that is not yet due (and still active) is returned
 * untouched, and the gateway idempotency key is derived from the upcoming period start so a
 * re-invocation for the same period cannot double-charge.
 *
 * A pending downgrade — recorded by ChangePlanAction as the subscription's latest change — is
 * applied here at the period boundary: the upcoming period is charged and billed at the target
 * (lower) plan.
 *
 * Outcomes:
 *   success  → current_period_* advance by the effective plan's interval, status returns to active,
 *              the grace clock clears, SubscriptionRenewed is dispatched, and a renewal change is
 *              recorded (from/to plan set when a downgrade was applied).
 *   failure  → an active/trialing subscription drops to past_due; a subscription already past_due or
 *              in grace is left as-is for the dunning ladder (EnterGraceAction / ExpireSubscriptionsAction)
 *              to escalate. The period is never advanced on a failed charge.
 *
 * Gateway I/O runs OUTSIDE any DB transaction. Money is integer minor units throughout.
 */
class RenewSubscriptionAction extends BaseAction
{
    public function __construct(
        private readonly PaymentGateway $gateway,
        private readonly AuditLogger $audit,
    ) {}

    public function execute(Subscription $subscription): Subscription
    {
        $status = $subscription->statusEnum();

        // Only due subscriptions in a chargeable lifecycle state are processed.
        if (! in_array($status, [
            SubscriptionStatus::Active,
            SubscriptionStatus::Trialing,
            SubscriptionStatus::PastDue,
            SubscriptionStatus::Grace,
        ], true)) {
            return $subscription;
        }

        $periodEnd = $subscription->currentPeriodEnd();
        if ($periodEnd !== null && $periodEnd->isFuture()) {
            return $subscription; // not due yet
        }

        // Resolve the plan to bill for the upcoming period, honouring a pending downgrade.
        $currentPlanId = $subscription->planId();
        $effectivePlan = $this->effectivePlanFor($subscription);
        $currency = $subscription->currency();
        $amountMinor = $effectivePlan->amountMinorFor($currency);

        // The new period starts where the old one ended (or now, if that is already in the past by
        // more than a full cycle we still anchor on the recorded end for deterministic keys).
        $newStart = $periodEnd ?? Carbon::now();
        $newEnd = $newStart->copy()->addMonths($effectivePlan->intervalEnum()->months());

        $key = $subscription->public_id.':r'.$newStart->format('Ymd');

        try {
            $charge = $this->gateway->charge(new ChargeRequest(
                reference: $subscription->public_id,
                amountMinor: $amountMinor,
                currency: $currency,
                description: 'HElbaron subscription renewal '.$subscription->public_id,
                idempotencyKey: $key,
            ));
            $succeeded = $charge->isSucceeded();
            $providerReference = $charge->providerReference;
        } catch (Throwable $e) {
            $succeeded = false;
            $providerReference = null;
        }

        if (! $succeeded) {
            return $this->markFailed($subscription, $status);
        }

        $planChanged = $effectivePlan->getKey() !== $currentPlanId;

        $this->transaction(function () use (
            $subscription,
            $effectivePlan,
            $currentPlanId,
            $amountMinor,
            $currency,
            $newStart,
            $newEnd,
            $providerReference,
            $planChanged
        ): void {
            $subscription->forceFill([
                'plan_id' => $effectivePlan->getKey(),
                'status' => SubscriptionStatus::Active->value,
                'current_period_start' => $newStart,
                'current_period_end' => $newEnd,
                'grace_ends_at' => null,
                'amount_minor' => $amountMinor,
                'currency' => $currency,
                'provider_reference' => $providerReference,
            ])->save();

            SubscriptionChange::create([
                'subscription_id' => $subscription->getKey(),
                'type' => SubscriptionChangeType::Renewal->value,
                'from_plan_id' => $planChanged ? $currentPlanId : null,
                'to_plan_id' => $planChanged ? $effectivePlan->getKey() : null,
                'amount_minor' => $amountMinor,
                'note' => $planChanged ? 'downgrade_applied' : null,
            ]);
        });

        SubscriptionRenewed::dispatch(
            (int) $subscription->getKey(),
            $subscription->userId(),
            (int) $effectivePlan->getKey(),
            $amountMinor,
            $currency,
        );

        $this->audit->log('commerce.subscription.renewed', $subscription, [
            'plan_id' => $effectivePlan->public_id,
            'amount_minor' => $amountMinor,
            'currency' => $currency,
            'plan_changed' => $planChanged,
        ]);

        return $subscription;
    }

    /**
     * The plan to bill for the upcoming period: a pending downgrade (the subscription's latest change
     * being a downgrade to a different plan) takes effect now; otherwise the current plan continues.
     */
    private function effectivePlanFor(Subscription $subscription): SubscriptionPlan
    {
        $currentPlan = $subscription->plan instanceof SubscriptionPlan
            ? $subscription->plan
            : $subscription->plan()->first();

        $latest = SubscriptionChange::query()
            ->where('subscription_id', $subscription->getKey())
            ->latest('id')
            ->first();

        if (
            $latest !== null
            && $latest->typeEnum() === SubscriptionChangeType::Downgrade
            && $latest->getAttribute('to_plan_id') !== null
            && (int) $latest->getAttribute('to_plan_id') !== $subscription->planId()
        ) {
            $target = SubscriptionPlan::query()->whereKey($latest->getAttribute('to_plan_id'))->first();

            if ($target instanceof SubscriptionPlan) {
                return $target->loadMissing('prices');
            }
        }

        if ($currentPlan instanceof SubscriptionPlan) {
            return $currentPlan->loadMissing('prices');
        }

        // Fallback: reload by id (should not happen for a persisted subscription).
        return SubscriptionPlan::query()->whereKey($subscription->planId())->firstOrFail()->loadMissing('prices');
    }

    /**
     * Settle a failed renewal: an active/trialing subscription drops to past_due (opening dunning);
     * one already past_due or in grace is left for the escalation actions.
     */
    private function markFailed(Subscription $subscription, SubscriptionStatus $status): Subscription
    {
        if ($status === SubscriptionStatus::Active || $status === SubscriptionStatus::Trialing) {
            $subscription->forceFill(['status' => SubscriptionStatus::PastDue->value])->save();

            $this->audit->log('commerce.subscription.renewal_failed', $subscription, [
                'from_status' => $status->value,
            ]);
        }

        return $subscription;
    }
}
