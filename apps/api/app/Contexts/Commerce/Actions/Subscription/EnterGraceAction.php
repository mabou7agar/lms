<?php

namespace App\Contexts\Commerce\Actions\Subscription;

use App\Contexts\Commerce\Enums\SubscriptionChangeType;
use App\Contexts\Commerce\Enums\SubscriptionStatus;
use App\Contexts\Commerce\Events\SubscriptionEnteredGrace;
use App\Contexts\Commerce\Models\Subscription;
use App\Contexts\Commerce\Models\SubscriptionChange;
use App\Platform\Shared\Actions\BaseAction;
use App\Platform\Shared\Audit\AuditLogger;
use Illuminate\Support\Carbon;

/**
 * Escalate a past_due subscription into its grace (dunning) window: the user keeps access until the
 * grace clock elapses, giving a last window for the renewal to succeed before expiry.
 *
 * Idempotent: only a past_due subscription is moved; anything else is returned untouched, and the
 * grace clock is only opened once (a subscription that already carries a future grace_ends_at keeps
 * it). Records an entered_grace change and dispatches SubscriptionEnteredGrace.
 */
class EnterGraceAction extends BaseAction
{
    public function __construct(
        private readonly AuditLogger $audit,
    ) {}

    public function execute(Subscription $subscription): Subscription
    {
        if ($subscription->statusEnum() !== SubscriptionStatus::PastDue) {
            return $subscription;
        }

        $graceDays = max(0, (int) config('commerce.subscriptions.grace_days', 3));
        $existingGrace = $subscription->graceEndsAt();
        $graceEndsAt = $existingGrace !== null && $existingGrace->isFuture()
            ? $existingGrace
            : Carbon::now()->addDays($graceDays);

        $this->transaction(function () use ($subscription, $graceEndsAt): void {
            $subscription->forceFill([
                'status' => SubscriptionStatus::Grace->value,
                'grace_ends_at' => $graceEndsAt,
            ])->save();

            SubscriptionChange::create([
                'subscription_id' => $subscription->getKey(),
                'type' => SubscriptionChangeType::EnteredGrace->value,
                'from_plan_id' => $subscription->planId(),
                'amount_minor' => 0,
                'note' => 'grace_until_'.$graceEndsAt->format('Y-m-d'),
            ]);
        });

        SubscriptionEnteredGrace::dispatch((int) $subscription->getKey(), $subscription->userId());

        $this->audit->log('commerce.subscription.entered_grace', $subscription, [
            'grace_ends_at' => $graceEndsAt->toIso8601String(),
        ]);

        return $subscription;
    }
}
