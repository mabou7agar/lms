<?php

use App\Platform\Identity\Models\User;
use App\Platform\Integration\Models\WebhookDelivery;
use App\Platform\Integration\Models\WebhookEndpoint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config()->set('queue.default', 'sync');
    Http::fake(['*' => Http::response('', 200)]);
});

it('replays a delivery by re-queuing a fresh delivery of the same payload', function (): void {
    Sanctum::actingAs(User::factory()->create());

    $endpoint = WebhookEndpoint::factory()->create();
    $original = WebhookDelivery::factory()->forEndpoint($endpoint)->create([
        'status' => 'failed',
        'payload' => ['order_id' => 'abc', 'total_minor' => 4200],
    ]);

    $res = $this->postJson(
        "/api/v1/integration/webhook-endpoints/{$endpoint->public_id}/deliveries/{$original->public_id}/replay",
    )->assertCreated();

    // A NEW delivery row exists, carrying the same payload and event type.
    expect(WebhookDelivery::query()->count())->toBe(2);

    $replayId = $res->json('data.id');
    $replay = WebhookDelivery::query()->where('public_id', $replayId)->firstOrFail();

    expect($replay->id)->not->toBe($original->id)
        ->and($replay->payload)->toEqual(['order_id' => 'abc', 'total_minor' => 4200])
        ->and($replay->event_type)->toBe($original->event_type)
        ->and($replay->status)->toBe('success'); // delivered immediately via the sync queue + faked Http

    Http::assertSent(fn ($request): bool => $request->url() === $endpoint->url);
});

it('lists an endpoint\'s deliveries', function (): void {
    Sanctum::actingAs(User::factory()->create());

    $endpoint = WebhookEndpoint::factory()->create();
    WebhookDelivery::factory()->forEndpoint($endpoint)->count(3)->create();

    $this->getJson("/api/v1/integration/webhook-endpoints/{$endpoint->public_id}/deliveries")
        ->assertOk()
        ->assertJsonCount(3, 'data');
});
