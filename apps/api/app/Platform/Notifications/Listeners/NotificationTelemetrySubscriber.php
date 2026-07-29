<?php

namespace App\Platform\Notifications\Listeners;

use App\Platform\Notifications\Events\NotificationDeadLettered;
use App\Platform\Notifications\Events\NotificationDelivered;
use App\Platform\Notifications\Events\NotificationFailed;
use App\Platform\Notifications\Events\NotificationQueued;
use App\Platform\Notifications\Events\NotificationSkipped;
use App\Platform\Notifications\Models\NotificationDelivery;
use App\Platform\Notifications\Support\NotificationLogContext;
use App\Platform\Notifications\Support\NotificationMetrics;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\Log;

/**
 * The single telemetry sink for the notification pipeline. It turns each observability-only
 * lifecycle event into (a) a structured, metadata-only log line and (b) an idempotent metric
 * counter. It never changes delivery behavior — it only reports what the Sprint 3 pipeline already
 * decided and persisted.
 *
 * Channel truthfulness is preserved verbatim: a disabled channel is reported as skipped and a
 * required-but-unconfigured channel as a configuration failure; success is only ever logged for a
 * genuine Sent delivery, and "delivered" (provider receipt) is never fabricated.
 */
class NotificationTelemetrySubscriber
{
    public function __construct(private readonly NotificationMetrics $metrics) {}

    public function onQueued(NotificationQueued $event): void
    {
        $this->safely(function () use ($event): void {
            $delivery = $event->delivery;
            Log::info('notification.queued', NotificationLogContext::for($delivery, ['event' => 'queued']));
            $this->metrics->increment('queued', $delivery->channel->value, $this->token($delivery, 'queued'));
        });
    }

    public function onSkipped(NotificationSkipped $event): void
    {
        $this->safely(function () use ($event): void {
            $delivery = $event->delivery;
            Log::info('notification.skipped_disabled', NotificationLogContext::for($delivery, ['event' => 'skipped']));
            $this->metrics->increment('skipped', $delivery->channel->value, $this->token($delivery, 'skipped'));
        });
    }

    public function onFailed(NotificationFailed $event): void
    {
        $this->safely(function () use ($event): void {
            $delivery = $event->delivery;
            $context = NotificationLogContext::for($delivery, ['event' => 'failed', 'reason' => $event->reason, 'retriable' => $event->retriable]);

            if ($event->retriable) {
                // A real send attempt threw and will be retried — this is the retry-visibility signal.
                Log::warning('notification.retry', $context);
                $this->metrics->increment('retries', $delivery->channel->value, $this->token($delivery, 'retry:'.$delivery->attempts));

                return;
            }

            // Terminal, non-retriable failure at creation (required channel, no working provider).
            Log::warning('notification.failed_configuration', $context);
            $this->metrics->increment('failed', $delivery->channel->value, $this->token($delivery, 'failed'));
        });
    }

    public function onDelivered(NotificationDelivered $event): void
    {
        $this->safely(function () use ($event): void {
            // In this pipeline the terminal success status is Sent (no provider confirms final
            // receipt), so this event represents "sent to and accepted by the provider".
            $delivery = $event->delivery;
            $context = NotificationLogContext::for($delivery, ['event' => 'sent']);

            Log::info('notification.sent', $context);
            $this->metrics->increment('sent', $delivery->channel->value, $this->token($delivery, 'sent'));

            if (isset($context['latency_ms']) && is_int($context['latency_ms'])) {
                $this->metrics->observeLatency($context['latency_ms'], $this->token($delivery, 'latency'));
            }
        });
    }

    public function onDeadLettered(NotificationDeadLettered $event): void
    {
        $this->safely(function () use ($event): void {
            $delivery = $event->delivery;

            // Dead-letter record: reason, retry count, final status and timestamp. Logged at error
            // level so the structured 'json' channel surfaces it to log-based alerting.
            Log::error('notification.dead_letter', NotificationLogContext::for($delivery, [
                'event' => 'dead_letter',
                'reason' => $delivery->last_error,
                'retry_count' => $delivery->attempts,
                'final_status' => $delivery->status->value,
                'dead_at' => optional($delivery->dead_at)->toIso8601String(),
            ]));

            $this->metrics->increment('dead_letter', $delivery->channel->value, $this->token($delivery, 'dead'));
        });
    }

    /**
     * Telemetry must never affect delivery. These handlers run synchronously inside the delivery
     * create/save, so any failure (e.g. a cache/log backend outage) is reported and swallowed rather
     * than propagating into — and breaking — the notification pipeline.
     */
    private function safely(callable $work): void
    {
        rescue($work, null);
    }

    /** @return array<class-string, string> */
    public function subscribe(Dispatcher $events): array
    {
        return [
            NotificationQueued::class => 'onQueued',
            NotificationSkipped::class => 'onSkipped',
            NotificationFailed::class => 'onFailed',
            NotificationDelivered::class => 'onDelivered',
            NotificationDeadLettered::class => 'onDeadLettered',
        ];
    }

    /** Stable idempotency token so a re-fired event never double-counts a metric. */
    private function token(NotificationDelivery $delivery, string $suffix): string
    {
        return $delivery->getKey().':'.$suffix;
    }
}
