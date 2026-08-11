<?php

use App\Platform\Integration\Jobs\DeliverWebhookJob;
use App\Platform\Integration\Models\WebhookDelivery;
use App\Platform\Integration\Models\WebhookEndpoint;
use App\Platform\Integration\Security\WebhookUrlGuard;
use App\Platform\Integration\Signing\WebhookSigner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config()->set('queue.default', 'sync');
});

function runDeliveryJob(int $deliveryId): void
{
    (new DeliverWebhookJob($deliveryId))->handle(app(WebhookSigner::class), app(WebhookUrlGuard::class));
}

it('records a successful delivery and clears the failure streak', function (): void {
    Http::fake(['*' => Http::response('ok', 200)]);

    $endpoint = WebhookEndpoint::factory()->create(['consecutive_failures' => 2]);
    $delivery = WebhookDelivery::factory()->forEndpoint($endpoint)->create();

    runDeliveryJob($delivery->id);

    $delivery->refresh();
    expect($delivery->status)->toBe('success')
        ->and($delivery->response_status)->toBe(200)
        ->and($delivery->response_ms)->not->toBeNull()
        ->and($delivery->signature)->toStartWith('sha256=')
        ->and($delivery->delivered_at)->not->toBeNull();

    expect($endpoint->fresh()->consecutive_failures)->toBe(0);
});

it('schedules a retry with backoff on a transient failure', function (): void {
    config()->set('integration.delivery.max_attempts', 5);
    config()->set('integration.delivery.backoff', [60, 120, 300]);
    Http::fake(['*' => Http::response('boom', 500)]);
    Queue::fake(); // capture the re-dispatched retry instead of running it

    $endpoint = WebhookEndpoint::factory()->create();
    $delivery = WebhookDelivery::factory()->forEndpoint($endpoint)->create();

    runDeliveryJob($delivery->id);

    $delivery->refresh();
    expect($delivery->status)->toBe('pending')
        ->and($delivery->attempts)->toBe(1)
        ->and($delivery->next_retry_at)->not->toBeNull()
        ->and($endpoint->fresh()->consecutive_failures)->toBe(1);

    Queue::assertPushed(DeliverWebhookJob::class);
});

it('exhausts retries and auto-disables the endpoint past the failure threshold', function (): void {
    // Small, immediate backoff so the sync retries cascade quickly to a terminal failure.
    config()->set('integration.delivery.max_attempts', 3);
    config()->set('integration.delivery.backoff', [0]);
    config()->set('integration.endpoint.failure_disable_threshold', 3);
    Http::fake(['*' => Http::response('boom', 500)]);

    $endpoint = WebhookEndpoint::factory()->create();
    $delivery = WebhookDelivery::factory()->forEndpoint($endpoint)->create();

    runDeliveryJob($delivery->id);

    $delivery->refresh();
    expect($delivery->status)->toBe('failed')
        ->and($delivery->attempts)->toBe(3)
        ->and($delivery->next_retry_at)->toBeNull();

    $endpoint->refresh();
    expect($endpoint->consecutive_failures)->toBeGreaterThanOrEqual(3)
        ->and($endpoint->active)->toBeFalse()
        ->and($endpoint->disabled_at)->not->toBeNull();

    Http::assertSentCount(3);
});

it('does not re-send a delivery that already succeeded (idempotent worker)', function (): void {
    Http::fake(['*' => Http::response('ok', 200)]);

    $endpoint = WebhookEndpoint::factory()->create();
    $delivery = WebhookDelivery::factory()->forEndpoint($endpoint)->create(['status' => 'success']);

    runDeliveryJob($delivery->id);

    Http::assertNothingSent();
});
