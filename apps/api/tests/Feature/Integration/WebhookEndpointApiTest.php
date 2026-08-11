<?php

use App\Platform\Identity\Models\User;
use App\Platform\Integration\Models\WebhookEndpoint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

function createEndpointPayload(array $overrides = []): array
{
    return array_replace([
        'name' => 'My CRM sync',
        'url' => 'https://hooks.example.test/crm',
        'event_types' => ['course.completed', 'payment.succeeded'],
    ], $overrides);
}

it('requires authentication', function (): void {
    $this->postJson('/api/v1/integration/webhook-endpoints', createEndpointPayload())
        ->assertUnauthorized();
});

it('creates an endpoint and reveals the secret exactly once', function (): void {
    Sanctum::actingAs(User::factory()->create());

    $res = $this->postJson('/api/v1/integration/webhook-endpoints', createEndpointPayload())
        ->assertCreated();

    expect($res->json('data.secret'))->toBeString()->not->toBeEmpty()
        ->and($res->json('data.endpoint.event_types'))->toEqual(['course.completed', 'payment.succeeded'])
        ->and($res->json('data.endpoint'))->not->toHaveKey('secret');

    // The show endpoint never returns the secret again.
    $publicId = $res->json('data.endpoint.id');
    $this->getJson("/api/v1/integration/webhook-endpoints/{$publicId}")
        ->assertOk()
        ->assertJsonMissingPath('data.secret');
});

it('rejects an unknown event name', function (): void {
    Sanctum::actingAs(User::factory()->create());

    $this->postJson('/api/v1/integration/webhook-endpoints', createEndpointPayload(['event_types' => ['not.a.real.event']]))
        ->assertStatus(422);
});

it('rejects a private/localhost destination URL (SSRF)', function (): void {
    Sanctum::actingAs(User::factory()->create());

    $this->postJson('/api/v1/integration/webhook-endpoints', createEndpointPayload(['url' => 'http://127.0.0.1/hook']))
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'WEBHOOK_URL_REJECTED');
});

it('rotates the secret and returns a new one', function (): void {
    Sanctum::actingAs(User::factory()->create());

    $created = $this->postJson('/api/v1/integration/webhook-endpoints', createEndpointPayload())->assertCreated();
    $publicId = $created->json('data.endpoint.id');
    $original = $created->json('data.secret');

    $rotated = $this->postJson("/api/v1/integration/webhook-endpoints/{$publicId}/rotate-secret")
        ->assertOk()
        ->json('data.secret');

    expect($rotated)->toBeString()->not->toBe($original);
});

it('updates subscribed events', function (): void {
    Sanctum::actingAs(User::factory()->create());

    $publicId = $this->postJson('/api/v1/integration/webhook-endpoints', createEndpointPayload())
        ->json('data.endpoint.id');

    $this->patchJson("/api/v1/integration/webhook-endpoints/{$publicId}", ['event_types' => ['certificate.issued']])
        ->assertOk()
        ->assertJsonPath('data.event_types', ['certificate.issued']);
});

it('disables and re-enables an endpoint', function (): void {
    Sanctum::actingAs(User::factory()->create());

    $publicId = $this->postJson('/api/v1/integration/webhook-endpoints', createEndpointPayload())
        ->json('data.endpoint.id');

    $this->postJson("/api/v1/integration/webhook-endpoints/{$publicId}/disable")
        ->assertOk()->assertJsonPath('data.active', false);

    $this->postJson("/api/v1/integration/webhook-endpoints/{$publicId}/enable")
        ->assertOk()->assertJsonPath('data.active', true);

    expect(WebhookEndpoint::query()->where('public_id', $publicId)->first()->consecutive_failures)->toBe(0);
});
