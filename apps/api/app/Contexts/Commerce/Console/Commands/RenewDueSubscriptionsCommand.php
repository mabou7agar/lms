<?php

namespace App\Contexts\Commerce\Console\Commands;

use App\Contexts\Commerce\Actions\Subscription\CancelSubscriptionAction;
use App\Contexts\Commerce\Actions\Subscription\EnterGraceAction;
use App\Contexts\Commerce\Actions\Subscription\ExpireSubscriptionsAction;
use App\Contexts\Commerce\Actions\Subscription\RenewSubscriptionAction;
use App\Contexts\Commerce\Enums\SubscriptionStatus;
use App\Contexts\Commerce\Models\Subscription;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Subscription billing worker (scheduled hourly). Advances the whole lifecycle in one idempotent
 * pass, each step delegating to a domain Action:
 *
 *   1. Finalise due cancel-at-period-end subscriptions (period elapsed) → immediate cancellation.
 *   2. Renew due subscriptions (period elapsed, chargeable state) → charge + advance, or drop to
 *      past_due on a declined charge. Applies any pending downgrade at the boundary.
 *   3. Escalate past_due subscriptions whose retry window has closed → grace (dunning).
 *   4. Expire subscriptions whose grace window has elapsed → expired.
 *
 * Every step guards on the current status, so a re-run within the same window is a no-op.
 */
class RenewDueSubscriptionsCommand extends Command
{
    protected $signature = 'commerce:renew-subscriptions';

    protected $description = 'Renew due subscriptions and advance dunning (grace/expiry) for lapsed ones.';

    public function handle(
        RenewSubscriptionAction $renew,
        CancelSubscriptionAction $cancel,
        EnterGraceAction $enterGrace,
        ExpireSubscriptionsAction $expire,
    ): int {
        $now = Carbon::now();
        $retryDays = max(0, (int) config('commerce.subscriptions.retry_days', 1));

        $renewed = 0;
        $canceled = 0;
        $graced = 0;

        // 1. Finalise scheduled cancellations whose period has elapsed.
        Subscription::query()
            ->where('cancel_at_period_end', true)
            ->whereIn('status', [SubscriptionStatus::Active->value, SubscriptionStatus::Trialing->value])
            ->where('current_period_end', '<=', $now)
            ->orderBy('id')
            ->chunkById(100, function ($subscriptions) use ($cancel, &$canceled): void {
                foreach ($subscriptions as $subscription) {
                    $cancel->execute($subscription, false);
                    $canceled++;
                }
            });

        // 2. Renew due subscriptions in a chargeable state (excluding those being canceled).
        Subscription::query()
            ->where('cancel_at_period_end', false)
            ->whereIn('status', [
                SubscriptionStatus::Active->value,
                SubscriptionStatus::Trialing->value,
                SubscriptionStatus::PastDue->value,
                SubscriptionStatus::Grace->value,
            ])
            ->where('current_period_end', '<=', $now)
            ->orderBy('id')
            ->chunkById(100, function ($subscriptions) use ($renew, &$renewed): void {
                foreach ($subscriptions as $subscription) {
                    $result = $renew->execute($subscription);
                    if ($result->statusEnum() === SubscriptionStatus::Active) {
                        $renewed++;
                    }
                }
            });

        // 3. Escalate past_due subscriptions whose retry window has closed into grace.
        Subscription::query()
            ->where('status', SubscriptionStatus::PastDue->value)
            ->where('current_period_end', '<=', $now->copy()->subDays($retryDays))
            ->orderBy('id')
            ->chunkById(100, function ($subscriptions) use ($enterGrace, &$graced): void {
                foreach ($subscriptions as $subscription) {
                    $enterGrace->execute($subscription);
                    $graced++;
                }
            });

        // 4. Expire subscriptions whose grace window has elapsed.
        $expired = $expire->execute();

        $this->info("Renewed {$renewed}, canceled {$canceled}, graced {$graced}, expired {$expired}.");

        return self::SUCCESS;
    }
}
