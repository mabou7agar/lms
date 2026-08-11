<?php

declare(strict_types=1);

namespace App\Platform\Integration\Jobs;

use App\Platform\Integration\Enums\DeliveryStatus;
use App\Platform\Integration\Exceptions\WebhookUrlNotAllowedException;
use App\Platform\Integration\Models\WebhookDelivery;
use App\Platform\Integration\Models\WebhookEndpoint;
use App\Platform\Integration\Security\WebhookUrlGuard;
use App\Platform\Integration\Signing\WebhookSigner;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Delivers ONE webhook delivery row to its endpoint on the dedicated `webhooks` queue: SSRF-checks
 * the URL, signs the body, POSTs it, records the response, and on failure schedules a retry with
 * config-driven exponential backoff — auto-disabling an endpoint after too many consecutive failures.
 *
 * Retry orchestration is explicit (attempts are tracked on the delivery row and the job re-dispatches
 * itself with a delay) rather than relying on the queue's own retry, so backoff, permanent-failure
 * and the consecutive-failure counter are deterministic and unit-testable.
 *
 * Idempotent: a delivery already in `success` is a no-op, so an at-least-once queue never double-sends.
 * Tenancy: the delivery is addressed by primary id; on a worker no tenant is resolved so the global
 * TenantScope no-ops and the row (and its endpoint) are reachable regardless of owning org.
 */
class DeliverWebhookJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /** Retry is orchestrated by this job itself; the queue must not also retry it. */
    public int $tries = 1;

    public function __construct(public readonly int $deliveryId)
    {
        $this->onQueue((string) config('integration.delivery.queue', 'webhooks'));
    }

    public function handle(WebhookSigner $signer, WebhookUrlGuard $guard): void
    {
        $delivery = WebhookDelivery::query()->find($this->deliveryId);

        if ($delivery === null || $delivery->status === DeliveryStatus::Success->value) {
            return;
        }

        $endpoint = $delivery->webhookEndpoint()->first();

        if ($endpoint === null) {
            $delivery->forceFill([
                'status' => DeliveryStatus::Failed->value,
                'error' => 'endpoint_missing',
                'next_retry_at' => null,
            ])->save();

            return;
        }

        if (! $endpoint->active) {
            $delivery->forceFill([
                'status' => DeliveryStatus::Failed->value,
                'error' => 'endpoint_disabled',
                'next_retry_at' => null,
            ])->save();

            return;
        }

        $body = $this->encodeBody($delivery->payload);

        // SSRF pre-flight (authoritative — DNS may have been re-pointed since registration).
        try {
            $guard->assertAllowed($endpoint->url);
        } catch (WebhookUrlNotAllowedException $e) {
            $this->registerFailure($delivery, $endpoint, null, null, 'blocked_url:'.$e->getMessage(), canRetry: false);

            return;
        }

        $timestamp = time();
        $headers = $signer->headers($endpoint->secret, $body, $delivery->public_id, $delivery->event_type, $timestamp);
        $delivery->forceFill(['signature' => $headers[WebhookSigner::SIGNATURE_HEADER]])->save();

        $startedAt = microtime(true);

        try {
            // Do NOT follow redirects: the SSRF guard validated only the initial URL, so a 3xx to an
            // internal address (169.254.169.254, ::1, 10.x) would otherwise bypass it. A redirect is
            // returned as a non-2xx response and recorded as a (permanent) failure below.
            $response = Http::withHeaders($headers)
                ->withoutRedirecting()
                ->timeout((int) config('integration.delivery.timeout', 10))
                ->withBody($body, 'application/json')
                ->post($endpoint->url);

            $ms = (int) round((microtime(true) - $startedAt) * 1000);

            if ($response->successful()) {
                $this->registerSuccess($delivery, $endpoint, $response->status(), $ms);

                return;
            }

            // 5xx / 429 are transient (retry); other 4xx are the receiver rejecting us (permanent).
            $status = $response->status();
            $canRetry = $status >= 500 || $status === 429;

            $this->registerFailure($delivery, $endpoint, $status, $ms, 'http_'.$status, $canRetry);
        } catch (Throwable $e) {
            $ms = (int) round((microtime(true) - $startedAt) * 1000);

            $this->registerFailure($delivery, $endpoint, null, $ms, substr($e->getMessage(), 0, 500), canRetry: true);
        }
    }

    private function registerSuccess(WebhookDelivery $delivery, WebhookEndpoint $endpoint, int $status, int $ms): void
    {
        $delivery->forceFill([
            'status' => DeliveryStatus::Success->value,
            'attempts' => $delivery->attempts + 1,
            'response_status' => $status,
            'response_ms' => $ms,
            'error' => null,
            'delivered_at' => now(),
            'next_retry_at' => null,
        ])->save();

        // A success clears the consecutive-failure streak.
        if ($endpoint->consecutive_failures !== 0) {
            $endpoint->forceFill(['consecutive_failures' => 0])->save();
        }
    }

    private function registerFailure(
        WebhookDelivery $delivery,
        WebhookEndpoint $endpoint,
        ?int $status,
        ?int $ms,
        string $error,
        bool $canRetry,
    ): void {
        $attempts = $delivery->attempts + 1;
        $maxAttempts = max(1, (int) config('integration.delivery.max_attempts', 5));
        $willRetry = $canRetry && $attempts < $maxAttempts;

        $nextRetryAt = $willRetry ? now()->addSeconds($this->backoffSeconds($attempts)) : null;

        $delivery->forceFill([
            'status' => $willRetry ? DeliveryStatus::Pending->value : DeliveryStatus::Failed->value,
            'attempts' => $attempts,
            'response_status' => $status,
            'response_ms' => $ms,
            'error' => $error,
            'next_retry_at' => $nextRetryAt,
        ])->save();

        // Track the endpoint's consecutive-failure streak and auto-disable past the threshold.
        $failures = $endpoint->consecutive_failures + 1;
        $threshold = max(1, (int) config('integration.endpoint.failure_disable_threshold', 10));

        $attributes = ['consecutive_failures' => $failures];

        if ($failures >= $threshold && $endpoint->active) {
            $attributes['active'] = false;
            $attributes['disabled_at'] = now();
        }

        $endpoint->forceFill($attributes)->save();

        if ($willRetry) {
            self::dispatch($this->deliveryId)->delay($nextRetryAt);
        }
    }

    /** Backoff seconds for the given (1-based) attempt count; the last configured value repeats. */
    private function backoffSeconds(int $attempt): int
    {
        /** @var list<int> $backoff */
        $backoff = array_values((array) config('integration.delivery.backoff', [10, 30, 120, 300, 900]));

        if ($backoff === []) {
            return 0;
        }

        $index = min($attempt - 1, count($backoff) - 1);

        return (int) $backoff[$index];
    }

    /** @param array<string, mixed> $payload */
    private function encodeBody(array $payload): string
    {
        return json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}';
    }
}
