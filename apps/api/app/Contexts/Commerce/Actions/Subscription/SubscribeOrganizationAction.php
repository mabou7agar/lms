<?php

namespace App\Contexts\Commerce\Actions\Subscription;

use App\Contexts\Commerce\Contracts\PaymentGateway;
use App\Contexts\Commerce\Enums\SubscriptionChangeType;
use App\Contexts\Commerce\Enums\SubscriptionStatus;
use App\Contexts\Commerce\Events\OrganizationSubscriptionCreated;
use App\Contexts\Commerce\Exceptions\SubscriptionException;
use App\Contexts\Commerce\Models\Subscription;
use App\Contexts\Commerce\Models\SubscriptionChange;
use App\Contexts\Commerce\Models\SubscriptionPlan;
use App\Contexts\Commerce\Payments\Data\ChargeRequest;
use App\Contexts\Commerce\Payments\Data\ChargeResult;
use App\Platform\Shared\Actions\BaseAction;
use App\Platform\Shared\Audit\AuditLogger;
use App\Platform\Shared\Seats\Contracts\SeatProvisioningPort;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Subscribe an ORGANIZATION to a plan, provision its seat pool, and charge the first period.
 *
 * This is the organization-subscriber counterpart to SubscribeAction. It deliberately does NOT
 * re-implement the subscription lifecycle: cancel, reactivate, change-plan, renewal, grace, expiry
 * and dunning all operate on the shared Subscription model and are reused verbatim for organization
 * subscriptions. Only creation differs, because an organization subscription must also provision a
 * seat pool sized by the purchased quantity.
 *
 * Reused shape (mirrors SubscribeAction exactly):
 *   - the subscription row (and its seat pool + opening change) is created and COMMITTED before any
 *     gateway I/O, so no network call runs inside a DB transaction and there is always a record to
 *     reconcile;
 *   - the subscription public_id + period start is the gateway idempotency key so a provider-side
 *     retry cannot double-charge;
 *   - a plan with trial_days starts trialing and is NOT charged now;
 *   - a declined first charge drops the subscription into past_due with a grace clock and raises
 *     SubscriptionException;
 *   - domain idempotency: an existing access-granting organization subscription to the same plan is
 *     returned untouched, so a double-submit never creates two live subscriptions (or two pools).
 *
 * Pricing: the recurring amount is the plan's per-currency price (a flat plan price), NOT a per-seat
 * multiple. `seats` is capacity only. This is what lets RenewSubscriptionAction / ChangePlanAction be
 * reused unchanged — they recompute the amount from the plan and never need to know the seat count.
 * (Per-seat pricing, if ever wanted, is a later additive change.)
 *
 * INVARIANT: an organization subscription sets organization_id and leaves user_id null (exactly one
 * subscriber). Money is integer minor units throughout.
 *
 * TENANCY (T1, later): organization_id is tenant-owned; the idempotency lookup below and the pool
 * provisioning must be tenant-scoped when tenant scoping lands.
 */
class SubscribeOrganizationAction extends BaseAction
{
    public function __construct(
        private readonly PaymentGateway $gateway,
        private readonly AuditLogger $audit,
        private readonly SeatProvisioningPort $seats,
    ) {}

    public function execute(int $organizationId, SubscriptionPlan $plan, int $seats, ?string $currency = null): Subscription
    {
        if ($seats < 1) {
            throw SubscriptionException::invalidSeatCount($seats);
        }

        $plan->loadMissing('prices');

        $price = $plan->priceFor($currency);
        if ($price === null) {
            throw SubscriptionException::missingPrice($plan->public_id, (string) $currency);
        }

        $resolvedCurrency = (string) $price->getAttribute('currency');
        $amountMinor = $price->amountMinor();

        // Domain idempotency: reuse an existing access-granting organization subscription to the
        // same plan (avoids a duplicate live subscription AND a duplicate seat pool).
        $existing = Subscription::query()
            ->where('organization_id', $organizationId)
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

        $planName = (string) ($plan->getAttribute('name') ?? 'plan');

        // Phase 1: create + COMMIT the subscription, its seat pool, and its opening change before any
        // gateway I/O. Pool provisioning is atomic with the subscription so the linkage never dangles.
        $subscription = $this->transaction(function () use (
            $organizationId,
            $plan,
            $planName,
            $seats,
            $resolvedCurrency,
            $amountMinor,
            $isTrial,
            $periodStart,
            $periodEnd
        ): Subscription {
            $subscription = Subscription::create([
                'organization_id' => $organizationId,
                'user_id' => null,
                'plan_id' => $plan->getKey(),
                'seats' => $seats,
                'status' => ($isTrial ? SubscriptionStatus::Trialing : SubscriptionStatus::Active)->value,
                'current_period_start' => $periodStart,
                'current_period_end' => $periodEnd,
                'trial_ends_at' => $isTrial ? $periodEnd : null,
                'currency' => $resolvedCurrency,
                'amount_minor' => $amountMinor,
                'provider' => (string) config('commerce.payment.provider'),
            ]);

            $seatPoolId = $this->seats->provisionPool(
                $organizationId,
                $planName.' seats ('.$subscription->public_id.')',
                $seats,
            );

            $subscription->forceFill(['seat_pool_id' => $seatPoolId])->save();

            SubscriptionChange::create([
                'subscription_id' => $subscription->getKey(),
                'type' => SubscriptionChangeType::Created->value,
                'to_plan_id' => $plan->getKey(),
                'amount_minor' => 0,
                'note' => $isTrial ? 'trial:org:seats='.$seats : 'org:seats='.$seats,
            ]);

            return $subscription;
        });

        OrganizationSubscriptionCreated::dispatch(
            (int) $subscription->getKey(),
            $organizationId,
            (int) $plan->getKey(),
            $seats,
            (int) $subscription->seatPoolId(),
        );

        $this->audit->log('commerce.subscription.org_created', $subscription, [
            'organization_id' => $organizationId,
            'plan_id' => $plan->public_id,
            'currency' => $resolvedCurrency,
            'amount_minor' => $amountMinor,
            'seats' => $seats,
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
                description: 'HElbaron organization subscription '.$subscription->public_id,
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
