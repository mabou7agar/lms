<?php

use App\Platform\Media\Models\MediaAsset;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;

uses(RefreshDatabase::class);

beforeEach(function () {
    config()->set('media.ingestion.default', 'fake');
});

/** POST a signed fake webhook with the raw body the signature was computed over. */
function postFakeWebhook(array $data): TestResponse
{
    $payload = json_encode($data);
    $sig = hash_hmac('sha256', $payload, (string) config('media.fake.webhook_secret', 'fake-media-secret'));

    return test()->call(
        'POST',
        '/api/v1/media/webhooks/fake',
        [], [], [],
        ['CONTENT_TYPE' => 'application/json', 'HTTP_X_FAKE_SIGNATURE' => $sig],
        $payload,
    );
}

it('processes a valid provider webhook (unauthenticated, signed)', function () {
    $asset = MediaAsset::factory()->processing()->create();

    postFakeWebhook([
        'id' => 'evt-100', 'type' => 'ready', 'provider_ref' => $asset->provider_ref,
        'status' => 'ready', 'playback_id' => 'pb-9',
    ])->assertOk();

    expect($asset->refresh()->status->value)->toBe('ready');
});

it('rejects a webhook with an invalid signature', function () {
    $asset = MediaAsset::factory()->processing()->create();
    $payload = json_encode(['id' => 'evt-bad', 'provider_ref' => $asset->provider_ref, 'status' => 'ready']);

    $this->call(
        'POST',
        '/api/v1/media/webhooks/fake',
        [], [], [],
        ['CONTENT_TYPE' => 'application/json', 'HTTP_X_FAKE_SIGNATURE' => 'nope'],
        $payload,
    )->assertStatus(400);

    expect($asset->refresh()->status->value)->toBe('processing');
});

it('is idempotent for a duplicate webhook delivery', function () {
    $asset = MediaAsset::factory()->processing()->create();
    $data = ['id' => 'evt-dupe', 'type' => 'ready', 'provider_ref' => $asset->provider_ref, 'status' => 'ready'];

    postFakeWebhook($data)->assertOk();
    postFakeWebhook($data)->assertOk(); // replay -> safe 200

    expect($asset->refresh()->status->value)->toBe('ready');
});
