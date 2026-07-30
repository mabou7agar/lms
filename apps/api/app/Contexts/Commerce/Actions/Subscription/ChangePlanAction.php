<?php

namespace App\Contexts\Commerce\Actions\Subscription;

use App\Contexts\Commerce\Contracts\PaymentGateway;
use App\Contexts\Commerce\Enums\SubscriptionChangeType;
use App\Contexts\Commerce\Events\SubscriptionChanged;
use App\Contexts\Commerce\Exceptions\SubscriptionException;
use App\Contexts\Commerce\Models\Subscription;
use App\Contexts\Commerce\Models\SubscriptionChange;
use App\Contexts\Commerce\Models\SubscriptionPlan;
use App\Contexts\Commerce\Payments\Data\ChargeRequest;
use App\Platform\Shared\Actions\BaseAction;
use App\Platform\Shared\Audit\AuditLogger;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Change a subscription's plan.
 *
 *   upgrade (new price > current) → takes effect immediately. The prorated difference for the unused
 *     remainder of the current period is charged NOW via the gateway (integer arithmetic, no float
 *     drift), the plan and recurring amount switch, the period dates are kept, an upgrade change is
 *     recorded, and SubscriptionChanged is dispatched. A declined proration charge changes nothing
 *     and raises SubscriptionException.
 *   downgrade (new price ≤ current) → deferred to the period boundary. A pending downgrade change is
 *     recorded (from/to plan) and applied by RenewSubscriptionAction when the next period is billed,
 *     so the user keeps the higher tier they already paid for. No charge, no event until it applies.
 *
 * The gateway idempotency key is derived from the subscription public_id, the target plan, and the
 * period so a retried upgrade cannot double-charge the proration. Money is integer minor units only.
 */
class ChangePlanAction extends BaseAction
{
    public function __construct(
        private readonly PaymentGateway $gateway,
        private readonly AuditLogger $audit,
    ) {}

    public function execute(Subscription $subscription, SubscriptionPlan $newPlan, ?string $currency = null): Subscription
    {
        if ((int) $newPlan->getKey() === $subscription->planId()) {
            throw SubscriptionException::invalidPlanChange($subscription->public_id);
        }

        $newPlan->loadMissing('prices');
        $resolvedCurrency = $currency ?? $subscription->currency();

        $newPrice = $newPlan->priceFor($resolvedCurrency);
        if ($newPrice === null) {
            throw SubscriptionException::missingPrice($newPlan->public_id, (string) $resolvedCurrency);
        }

        $newAmount = $newPrice->amountMinor();
        $currentAmount = $subscription->amountMinor();

        return $newAmount > $currentAmount
            ? $this->upgrade($subscription, $newPlan, (string) $newPrice->getAttribute('currency'), $newAmount, $currentAmount)
            : $this->scheduleDowngrade($subscription, $newPlan);
    }

    /** Immediate upgrade: charge the prorated difference, then switch plan + amount. */
    private function upgrade(
        Subscription $subscription,
        SubscriptionPlan $newPlan,
        string $currency,
        int $newAmount,
        int $currentAmount,
    ): Subscription {
        $prorationMinor = $this->proration($subscription, $newAmount - $currentAmount);

        if ($prorationMinor > 0) {
            $key = $subscription->public_id.':up'.$newPlan->getKey().':'
                .($subscription->currentPeriodEnd()?->format('Ymd') ?? Carbon::now()->format('Ymd'));

            try {
                $charge = $this->gateway->charge(new ChargeRequest(
                    reference: $subscription->public_id,
                    amountMinor: $prorationMinor,
                    currency: $currency,
                    description: 'HElbaron subscription upgrade '.$subscription->public_id,
                    idempotencyKey: $key,
                ));
            } catch (Throwable $e) {
                throw SubscriptionException::chargeFailed($subscription->public_id);
            }

            if (! $charge->isSucceeded()) {
                throw SubscriptionException::chargeFailed($subscription->public_id);
            }
        }

        $fromPlanId = $subscription->planId();

        $this->transaction(function () use ($subscription, $newPlan, $currency, $newAmount, $fromPlanId, $prorationMinor): void {
            $subscription->forceFill([
                'plan_id' => $newPlan->getKey(),
                'amount_minor' => $newAmount,
                'currency' => $currency,
            ])->save();

            SubscriptionChange::create([
                'subscription_id' => $subscription->getKey(),
                'type' => SubscriptionChangeType::Upgrade->value,
                'from_plan_id' => $fromPlanId,
                'to_plan_id' => $newPlan->getKey(),
                'amount_minor' => $prorationMinor,
                'note' => 'immediate',
            ]);
        });

        SubscriptionChanged::dispatch(
            (int) $subscription->getKey(),
            $subscription->userId(),
            $fromPlanId,
            (int) $newPlan->getKey(),
            SubscriptionChangeType::Upgrade->value,
        );

        $this->audit->log('commerce.subscription.upgraded', $subscription, [
            'to_plan_id' => $newPlan->public_id,
            'proration_minor' => $prorationMinor,
            'currency' => $currency,
        ]);

        return $subscription;
    }

    /** Record a downgrade to take effect at the next period boundary. */
    private function scheduleDowngrade(Subscription $subscription, SubscriptionPlan $newPlan): Subscription
    {
        // Idempotency: a matching pending downgrade already scheduled is a no-op.
        $latest = SubscriptionChange::query()
            ->where('subscription_id', $subscription->getKey())
            ->latest('id')
            ->first();

        if (
            $latest !== null
            && $latest->typeEnum() === SubscriptionChangeType::Downgrade
            && (int) $latest->getAttribute('to_plan_id') === (int) $newPlan->getKey()
        ) {
            return $subscription;
        }

        SubscriptionChange::create([
            'subscription_id' => $subscription->getKey(),
            'type' => SubscriptionChangeType::Downgrade->value,
            'from_plan_id' => $subscription->planId(),
            'to_plan_id' => $newPlan->getKey(),
            'amount_minor' => 0,
            'note' => 'effective_at_period_end',
        ]);

        $this->audit->log('commerce.subscription.downgrade_scheduled', $subscription, [
            'to_plan_id' => $newPlan->public_id,
            'effective' => $subscription->currentPeriodEnd()?->toIso8601String(),
        ]);

        return $subscription;
    }

    /**
     * Prorate a per-period delta across the unused remainder of the current period using integer
     * arithmetic only (no floats): delta * remainingSeconds / totalSeconds, floored. Returns 0 when
     * the period window is unknown or already elapsed.
     */
    private function proration(Subscription $subscription, int $deltaMinor): int
    {
        if ($deltaMinor <= 0) {
            return 0;
        }

        $start = $subscription->currentPeriodStart();
        $end = $subscription->currentPeriodEnd();

        if ($start === null || $end === null) {
            return $deltaMinor;
        }

        $now = Carbon::now();
        $totalSeconds = $end->getTimestamp() - $start->getTimestamp();
        $remainingSeconds = $end->getTimestamp() - $now->getTimestamp();

        if ($totalSeconds <= 0 || $remainingSeconds <= 0) {
            return 0;
        }

        if ($remainingSeconds >= $totalSeconds) {
            return $deltaMinor;
        }

        return intdiv($deltaMinor * $remainingSeconds, $totalSeconds);
    }
}
