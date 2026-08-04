<?php

namespace App\Contexts\Commerce\Actions\Subscription;

use App\Contexts\Commerce\Contracts\PaymentGateway;
use App\Contexts\Commerce\Enums\SubscriptionChangeType;
use App\Contexts\Commerce\Enums\SubscriptionStatus;
use App\Contexts\Commerce\Events\SubscriptionRenewed;
use App\Contexts\Commerce\Models\Subscription;
use App\Contexts\Commerce\Models\SubscriptionChange;
use App\Contexts\Commerce\Models\SubscriptionPlan;
use App\Contexts\Commerce\Models\SubscriptionRenewalClaim;
use App\Contexts\Commerce\Payments\Data\ChargeRequest;
use App\Platform\Shared\Actions\BaseAction;
use App\Platform\Shared\Audit\AuditLogger;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Charge a due subscription for its next period and advance it.
 *
 * A subscription is "due" once current_period_end has passed. This action is the single per-period
 * charge point and is idempotent at two levels: a subscription that is not yet due (and still active)
 * is returned untouched, and — because only the Stripe adapter honours ChargeRequest::idempotencyKey
 * while Paymob/Tap/Moyasar/HyperPay/APS ignore it — a DB-enforced renewal claim guarantees at most
 * one charge per billing period even under concurrent scheduler passes.
 *
 * Concurrency model:
 *   1. A short DB transaction locks the subscription row (lockForUpdate), RE-READS the authoritative
 *      current_period_end, and only proceeds if it STILL equals the period end the caller observed
 *      as due. If the fresh period end has moved past the observed one, another worker already
 *      renewed this exact period (an isFuture() check alone is insufficient for a subscription
 *      several intervals in arrears, where even the advanced period is still in the past), so this
 *      pass bails without charging. Because the subscription row is lockForUpdate-held, all racing
 *      passes on the same subscription are serialized, so the period is CLAIMED with a
 *      SELECT-existing-then-INSERT of SubscriptionRenewalClaim(subscription_id, period_start): if a
 *      claim for the observed period already exists, another worker owns the charge and this pass
 *      returns untouched. No unique-violation is ever caught mid-transaction (which on Postgres would
 *      poison the transaction, SQLSTATE 25P02); the UNIQUE (subscription_id, period_start) index
 *      remains only as defense-in-depth behind the lock.
 *   2. The gateway charge runs OUTSIDE any DB transaction.
 *   3. On success the period advances and a Renewal change row is recorded; on failure the claim is
 *      DELETED so a genuine later retry for the same still-due period can re-attempt.
 *
 * A pending downgrade — recorded by ChangePlanAction as the subscription's latest change — is applied
 * here at the period boundary: the upcoming period is charged and billed at the target (lower) plan.
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
    /** Lifecycle states from which a due subscription may be renewed. */
    private const RENEWABLE = [
        SubscriptionStatus::Active,
        SubscriptionStatus::Trialing,
        SubscriptionStatus::PastDue,
        SubscriptionStatus::Grace,
    ];

    public function __construct(
        private readonly PaymentGateway $gateway,
        private readonly AuditLogger $audit,
    ) {}

    public function execute(Subscription $subscription): Subscription
    {
        // Cheap pre-check on the passed-in (possibly stale) instance to avoid a transaction for the
        // common not-due / non-chargeable case; the authoritative re-check happens under the lock.
        if (! in_array($subscription->statusEnum(), self::RENEWABLE, true)) {
            return $subscription;
        }

        $observedEnd = $subscription->currentPeriodEnd();
        if ($observedEnd !== null && $observedEnd->isFuture()) {
            return $subscription; // not due yet
        }

        // --- Claim phase: short DB transaction, NO gateway I/O. ---------------------------------
        // Lock the row, re-read the authoritative period end, re-assert still-due, and atomically
        // claim the period. Any bail-out returns null and no charge is attempted.
        /**
         * @var array{
         *     subscription: Subscription,
         *     plan: SubscriptionPlan,
         *     currentPlanId: int,
         *     amountMinor: int,
         *     currency: string,
         *     newStart: Carbon,
         *     newEnd: Carbon
         * }|null $claim
         */
        $claim = $this->transaction(function () use ($subscription, $observedEnd): ?array {
            $fresh = Subscription::query()
                ->whereKey($subscription->getKey())
                ->lockForUpdate()
                ->first();

            if ($fresh === null || ! in_array($fresh->statusEnum(), self::RENEWABLE, true)) {
                return null;
            }

            $freshEnd = $fresh->currentPeriodEnd();

            // Only renew the EXACT period the caller observed as due. If the authoritative period end
            // has moved past what we observed, another worker already renewed this period — bail
            // without charging. (isFuture() alone is insufficient: for a subscription several
            // intervals in arrears the already-advanced period is itself still in the past.)
            if (! $this->sameInstant($freshEnd, $observedEnd)) {
                return null;
            }
            if ($freshEnd !== null && $freshEnd->isFuture()) {
                return null; // defensive: not actually due under the lock
            }

            $currentPlanId = $fresh->planId();
            $effectivePlan = $this->effectivePlanFor($fresh);
            $currency = $fresh->currency();
            $amountMinor = $effectivePlan->amountMinorFor($currency);

            $newStart = $freshEnd ?? Carbon::now();
            $newEnd = $newStart->copy()->addMonths($effectivePlan->intervalEnum()->months());

            // Claim the period. The subscription row is lockForUpdate-held here, so all racing passes
            // on this subscription are serialized: a SELECT-then-INSERT is race-free, and no
            // unique-violation is ever caught inside the transaction (which on Postgres would poison
            // it, SQLSTATE 25P02). The UNIQUE index stays as defense-in-depth behind the lock.
            $alreadyClaimed = SubscriptionRenewalClaim::query()
                ->where('subscription_id', $fresh->getKey())
                ->where('period_start', $newStart)
                ->exists();

            if ($alreadyClaimed) {
                return null; // a concurrent worker already claimed this exact period; it owns the charge
            }

            SubscriptionRenewalClaim::create([
                'subscription_id' => $fresh->getKey(),
                'period_start' => $newStart,
            ]);

            return [
                'subscription' => $fresh,
                'plan' => $effectivePlan,
                'currentPlanId' => $currentPlanId,
                'amountMinor' => $amountMinor,
                'currency' => $currency,
                'newStart' => $newStart,
                'newEnd' => $newEnd,
            ];
        });

        if ($claim === null) {
            return $subscription; // not due / already handled / claimed by a racing worker
        }

        $fresh = $claim['subscription'];
        $effectivePlan = $claim['plan'];
        $currentPlanId = $claim['currentPlanId'];
        $amountMinor = $claim['amountMinor'];
        $currency = $claim['currency'];
        $newStart = $claim['newStart'];
        $newEnd = $claim['newEnd'];

        // --- Charge phase: OUTSIDE any DB transaction. -----------------------------------------
        // The idempotency key is derived from the (deterministic) new period start, so a re-attempt
        // for the same period reuses it on the one gateway that honours it.
        $key = $fresh->public_id.':r'.$newStart->format('Ymd');

        try {
            $charge = $this->gateway->charge(new ChargeRequest(
                reference: $fresh->public_id,
                amountMinor: $amountMinor,
                currency: $currency,
                description: 'HElbaron subscription renewal '.$fresh->public_id,
                idempotencyKey: $key,
            ));
            $succeeded = $charge->isSucceeded();
            $providerReference = $charge->providerReference;
        } catch (Throwable) {
            $succeeded = false;
            $providerReference = null;
        }

        if (! $succeeded) {
            // Release the claim so a genuine later retry for the same still-due period can re-attempt.
            $this->releaseClaim($fresh, $newStart);

            return $this->markFailed($fresh);
        }

        $planChanged = $effectivePlan->getKey() !== $currentPlanId;

        $this->transaction(function () use (
            $fresh,
            $effectivePlan,
            $currentPlanId,
            $amountMinor,
            $currency,
            $newStart,
            $newEnd,
            $providerReference,
            $planChanged
        ): void {
            $fresh->forceFill([
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
                'subscription_id' => $fresh->getKey(),
                'type' => SubscriptionChangeType::Renewal->value,
                'from_plan_id' => $planChanged ? $currentPlanId : null,
                'to_plan_id' => $planChanged ? $effectivePlan->getKey() : null,
                'amount_minor' => $amountMinor,
                'note' => $planChanged ? 'downgrade_applied' : null,
            ]);
        });

        SubscriptionRenewed::dispatch(
            (int) $fresh->getKey(),
            $fresh->userId(),
            (int) $effectivePlan->getKey(),
            $amountMinor,
            $currency,
        );

        $this->audit->log('commerce.subscription.renewed', $fresh, [
            'plan_id' => $effectivePlan->public_id,
            'amount_minor' => $amountMinor,
            'currency' => $currency,
            'plan_changed' => $planChanged,
        ]);

        return $fresh;
    }

    /**
     * True when two nullable period-end instants denote the same point in time (both null counts as
     * equal). Used to confirm, under the lock, that the fresh period end still matches the period the
     * caller observed as due before proceeding to charge it.
     */
    private function sameInstant(?Carbon $a, ?Carbon $b): bool
    {
        if ($a === null || $b === null) {
            return $a === $b;
        }

        return $a->equalTo($b);
    }

    /**
     * Release the period claim after a failed charge so a genuine later retry for the same still-due
     * period can re-claim and re-attempt. Never called on success — a successful claim is permanent.
     */
    private function releaseClaim(Subscription $subscription, Carbon $periodStart): void
    {
        $this->transaction(function () use ($subscription, $periodStart): void {
            SubscriptionRenewalClaim::query()
                ->where('subscription_id', $subscription->getKey())
                ->where('period_start', $periodStart)
                ->delete();
        });
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
    private function markFailed(Subscription $subscription): Subscription
    {
        $status = $subscription->statusEnum();

        if ($status === SubscriptionStatus::Active || $status === SubscriptionStatus::Trialing) {
            $subscription->forceFill(['status' => SubscriptionStatus::PastDue->value])->save();

            $this->audit->log('commerce.subscription.renewal_failed', $subscription, [
                'from_status' => $status->value,
            ]);
        }

        return $subscription;
    }
}
