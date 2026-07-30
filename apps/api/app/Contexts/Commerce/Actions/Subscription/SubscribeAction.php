<?php

namespace App\Contexts\Commerce\Actions\Subscription;

use App\Contexts\Commerce\Contracts\PaymentGateway;
use App\Contexts\Commerce\Enums\SubscriptionChangeType;
use App\Contexts\Commerce\Enums\SubscriptionStatus;
use App\Contexts\Commerce\Events\SubscriptionCreated;
use App\Contexts\Commerce\Exceptions\SubscriptionException;
use App\Contexts\Commerce\Models\Subscription;
use App\Contexts\Commerce\Models\SubscriptionChange;
use App\Contexts\Commerce\Models\SubscriptionPlan;
use App\Contexts\Commerce\Payments\Data\ChargeRequest;
use App\Contexts\Commerce\Payments\Data\ChargeResult;
use App\Platform\Shared\Actions\BaseAction;
use App\Platform\Shared\Audit\AuditLogger;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Subscribe a user to a plan and charge the first period.
 *
 * Idempotent at the domain level: if the user already holds an access-granting subscription to the
 * same plan it is returned untouched, so a double-submit never creates two live subscriptions.
 *
 * Shape mirrors the checkout flow — the subscription row is created and COMMITTED before any gateway
 * I/O, so no network call runs inside a DB transaction and there is always a record to reconcile.
 * The subscription public_id is the gateway idempotency key (suffixed with the period start) so a
 * provider-side retry cannot double-charge. A plan with trial_days starts trialing and is NOT
 * charged now — the first charge happens when the trial ends and the renewal runs. A declined first
 * charge drops the subscription into past_due with a grace clock and raises SubscriptionException.
 *
 * Money is integer minor units throughout.
 */
class SubscribeAction extends BaseAction
{
    public function __construct(
        private readonly PaymentGateway $gateway,
        private readonly AuditLogger $audit,
    ) {}

    public function execute(int $userId, SubscriptionPlan $plan, ?string $currency = null): Subscription
    {
        $plan->loadMissing('prices');

        $price = $plan->priceFor($currency);
        if ($price === null) {
            throw SubscriptionException::missingPrice($plan->public_id, (string) $currency);
        }

        $resolvedCurrency = (string) $price->getAttribute('currency');
        $amountMinor = $price->amountMinor();

        // Domain idempotency: reuse an existing access-granting subscription to the same plan.
        $existing = Subscription::query()
            ->where('user_id', $userId)
            ->where('plan_id', $plan->getKey())
            ->whereIn('status', Subscription::accessGrantingStatusValues())
            ->latest('id')
            ->first();

        if ($existing !== null && $existing->isActiveNow()) {
            return $existing;
        }

        $now = Carbon::now();
        $trialDays = $plan->trialDays();
        $isTrial = $trialDays > 0;

        $periodStart = $now->copy();
        $periodEnd = $isTrial
            ? $now->copy()->addDays($trialDays)
            : $now->copy()->addMonths($plan->intervalEnum()->months());

        // Phase 1: create + COMMIT the subscription and its opening change before any gateway I/O.
        $subscription = $this->transaction(function () use (
            $userId,
            $plan,
            $resolvedCurrency,
            $amountMinor,
            $isTrial,
            $periodStart,
            $periodEnd

        ): Subscription {
            $subscription = Subscription::create([
                'user_id' => $userId,
                'plan_id' => $plan->getKey(),
                'status' => ($isTrial ? SubscriptionStatus::Trialing : SubscriptionStatus::Active)->value,
                'current_period_start' => $periodStart,
                'current_period_end' => $periodEnd,
                'trial_ends_at' => $isTrial ? $periodEnd : null,
                'currency' => $resolvedCurrency,
                'amount_minor' => $amountMinor,
                'provider' => (string) config('commerce.payment.provider'),
            ]);

            SubscriptionChange::create([
                'subscription_id' => $subscription->getKey(),
                'type' => SubscriptionChangeType::Created->value,
                'to_plan_id' => $plan->getKey(),
                'amount_minor' => 0,
                'note' => $isTrial ? 'trial' : null,
            ]);

            return $subscription;
        });

        SubscriptionCreated::dispatch((int) $subscription->getKey(), $userId, (int) $plan->getKey());

        $this->audit->log('commerce.subscription.created', $subscription, [
            'plan_id' => $plan->public_id,
            'currency' => $resolvedCurrency,
            'amount_minor' => $amountMinor,
            'trial' => $isTrial,
        ]);

        // A trial does not charge now; the first charge runs when the trial ends and it renews.
        if ($isTrial) {
            return $subscription;
        }

        // Phase 2: charge the first period OUTSIDE any DB transaction.
        $key = $subscription->public_id.':p'.$periodStart->format('Ymd');

        try {
            $charge = $this->gateway->charge(new ChargeRequest(
                reference: $subscription->public_id,
                amountMinor: $amountMinor,
                currency: $resolvedCurrency,
                description: 'HElbaron subscription '.$subscription->public_id,
                idempotencyKey: $key,
            ));
        } catch (Throwable $e) {
            $this->markPastDue($subscription);

            throw SubscriptionException::chargeFailed($subscription->public_id);
        }

        if (! $charge->isSucceeded()) {
            $this->markPastDue($subscription);

            throw SubscriptionException::chargeFailed($subscription->public_id);
        }

        $this->settleCharge($subscription, $charge);

        return $subscription;
    }

    /** Record a successful first-period charge's provider reference on the subscription. */
    private function settleCharge(Subscription $subscription, ChargeResult $charge): void
    {
        $subscription->forceFill([
            'provider_reference' => $charge->providerReference,
        ])->save();
    }

    /** Drop a subscription whose first charge failed into past_due with a grace clock. */
    private function markPastDue(Subscription $subscription): void
    {
        $graceDays = max(0, (int) config('commerce.subscriptions.grace_days', 3));

        $subscription->forceFill([
            'status' => SubscriptionStatus::PastDue->value,
            'grace_ends_at' => Carbon::now()->addDays($graceDays),
        ])->save();
    }
}
