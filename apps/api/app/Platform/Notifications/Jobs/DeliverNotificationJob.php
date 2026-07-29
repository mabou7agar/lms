<?php

namespace App\Platform\Notifications\Jobs;

use App\Platform\Notifications\Channels\ChannelManager;
use App\Platform\Notifications\Enums\DeliveryStatus;
use App\Platform\Notifications\Events\NotificationDeadLettered;
use App\Platform\Notifications\Events\NotificationDelivered;
use App\Platform\Notifications\Models\NotificationDelivery;
use App\Platform\Notifications\Services\RateLimiterService;
use App\Platform\Notifications\Services\TemplateRenderer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Delivers a single NotificationDelivery via its channel.
 *
 * Three correctness properties this job guarantees:
 *
 *   Rate limiting never buries a message (C2). When the per-user window is exceeded the job
 *   RE-DISPATCHES a delayed copy of itself and returns cleanly — consuming neither a delivery
 *   attempt nor a job try. The old code called release(), which counts toward tries(), so a
 *   rate-limited message could exhaust its tries and be dead-lettered having never been attempted.
 *   (Assumes the notifications queue is asynchronous, which it is — a dedicated Horizon supervisor.)
 *
 *   No duplicate send under concurrency or queue restart. The job claims the delivery with a single
 *   atomic conditional UPDATE (pending/processing -> processing, attempts+1). If it claims zero rows
 *   another execution already has it, and this one returns. A retriable failure resets the row to
 *   pending so the retry can re-claim; a crash mid-flight leaves it processing, which failed() and
 *   the next claim recover.
 *
 *   Truthful terminal status. Success -> Sent; exhausted real attempts -> Dead. Disabled and
 *   misconfigured channels never reach this job (the dispatcher records their status directly).
 */
class DeliverNotificationJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(public readonly int $deliveryId) {}

    /** @return array<int, int> */
    public function backoff(): array
    {
        return (array) config('notifications.retry.backoff_seconds', [10, 60, 300]);
    }

    public function tries(): int
    {
        return (int) config('notifications.retry.max_attempts', 3);
    }

    public function handle(ChannelManager $channels, TemplateRenderer $renderer, RateLimiterService $limiter): void
    {
        $delivery = NotificationDelivery::with('notification.user')->find($this->deliveryId);

        if ($delivery === null || ! $delivery->status->isClaimable()) {
            return; // already terminal, or gone — idempotent
        }

        if (! $limiter->allow($delivery->notification->user_id)) {
            // Defer without consuming a delivery attempt or a job try: a fresh delayed job re-attempts
            // once the window clears, and the delivery stays pending. This is why rate limiting can
            // never dead-letter a message that was never actually sent.
            self::dispatch($this->deliveryId)
                ->onQueue((string) config('notifications.queue', 'notifications'))
                ->delay(now()->addSeconds((int) config('notifications.rate_limit.retry_after_seconds', 30)));

            return;
        }

        // Atomic claim: exactly one execution transitions the row to processing and increments the
        // real-attempt counter. A concurrent or restarted execution that claims 0 rows bows out.
        $claimed = NotificationDelivery::whereKey($this->deliveryId)
            ->whereIn('status', [DeliveryStatus::Pending->value, DeliveryStatus::Processing->value])
            ->update([
                'status' => DeliveryStatus::Processing->value,
                'attempts' => DB::raw('attempts + 1'),
            ]);

        if ($claimed === 0) {
            return; // another worker owns this delivery
        }

        $delivery->refresh();

        try {
            $notification = $delivery->notification;
            $rendered = $renderer->render($notification->type, $delivery->channel, $notification->locale, (array) $notification->data);

            $channels->resolve($delivery->channel)->send($delivery, $rendered);

            $delivery->forceFill([
                'status' => DeliveryStatus::Sent->value,
                'sent_at' => now(),
                'last_error' => null,
            ])->save();

            NotificationDelivered::dispatch($delivery);
        } catch (Throwable $e) {
            $delivery->forceFill(['last_error' => substr($e->getMessage(), 0, 500)])->save();

            if ($delivery->attempts >= $this->tries()) {
                $delivery->forceFill(['status' => DeliveryStatus::Dead->value, 'dead_at' => now()])->save();
                NotificationDeadLettered::dispatch($delivery);

                return; // exhausted real attempts — stop retrying
            }

            // Return the row to pending so the retried job can re-claim it, then retry with backoff.
            $delivery->forceFill(['status' => DeliveryStatus::Pending->value])->save();

            throw $e;
        }
    }

    public function failed(Throwable $e): void
    {
        $delivery = NotificationDelivery::find($this->deliveryId);

        // Recover a delivery left claimable by an unexpected job death (timeout, worker kill): a job
        // that reaches failed() has genuinely exhausted its retries, so this is a real dead-letter.
        if ($delivery !== null && $delivery->status->isClaimable()) {
            $delivery->forceFill([
                'status' => DeliveryStatus::Dead->value,
                'dead_at' => now(),
                'last_error' => substr($e->getMessage(), 0, 500),
            ])->save();

            NotificationDeadLettered::dispatch($delivery);
        }
    }
}
