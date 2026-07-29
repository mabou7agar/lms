<?php

use App\Platform\Media\Events\MediaFailed;
use App\Platform\Media\Events\MediaReady;
use App\Platform\Media\Exceptions\MediaTransitionException;
use App\Platform\Media\Exceptions\MediaUploadTokenException;
use App\Platform\Media\Exceptions\MediaWebhookSignatureException;
use App\Platform\Media\Models\MediaAsset;
use App\Platform\Media\Models\MediaWebhookEvent;
use App\Platform\Media\Services\MediaIngestionService;
use App\Platform\Shared\Media\Enums\MediaProvider;
use App\Platform\Shared\Media\Enums\MediaStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;

uses(RefreshDatabase::class);

beforeEach(function () {
    config()->set('media.ingestion.default', 'fake');
    $this->svc = app(MediaIngestionService::class);
});

/** Build a signed fake webhook body + headers. */
function fakeWebhook(array $data): array
{
    $payload = json_encode($data);
    $sig = hash_hmac('sha256', $payload, (string) config('media.fake.webhook_secret', 'fake-media-secret'));

    return [$payload, ['X-Fake-Signature' => $sig]];
}

it('finalizes an upload and transitions to ready via provider verification', function () {
    Event::fake([MediaReady::class]);
    $asset = MediaAsset::factory()->awaitingUpload()->create();

    $updated = $this->svc->finalizeUpload($asset, (string) $asset->upload_token);

    expect($updated->status)->toBe(MediaStatus::Ready)
        ->and($updated->playback_id)->not->toBeNull()
        ->and($updated->upload_token_consumed_at)->not->toBeNull();

    Event::assertDispatched(MediaReady::class);
});

it('rejects an invalid finalize token', function () {
    $asset = MediaAsset::factory()->awaitingUpload()->create();

    $this->svc->finalizeUpload($asset, 'wrong-token');
})->throws(MediaUploadTokenException::class);

it('rejects a reused finalize token', function () {
    $asset = MediaAsset::factory()->awaitingUpload()->tokenConsumed()->create();

    $this->svc->finalizeUpload($asset, (string) $asset->upload_token);
})->throws(MediaUploadTokenException::class);

it('rejects an expired finalize token', function () {
    $asset = MediaAsset::factory()->tokenExpired()->create();

    $this->svc->finalizeUpload($asset, (string) $asset->upload_token);
})->throws(MediaUploadTokenException::class);

it('processes a valid webhook and advances the asset', function () {
    $asset = MediaAsset::factory()->processing()->create();
    [$payload, $headers] = fakeWebhook([
        'id' => 'evt-1',
        'type' => 'ready',
        'provider_ref' => $asset->provider_ref,
        'status' => 'ready',
        'playback_id' => 'pb-123',
    ]);

    $this->svc->processWebhook(MediaProvider::Fake, $payload, $headers);

    expect($asset->refresh()->status)->toBe(MediaStatus::Ready)
        ->and($asset->playback_id)->toBe('pb-123');
});

it('rejects a webhook with an invalid signature', function () {
    $asset = MediaAsset::factory()->processing()->create();
    $payload = json_encode(['id' => 'evt-x', 'provider_ref' => $asset->provider_ref, 'status' => 'ready']);

    $this->svc->processWebhook(MediaProvider::Fake, $payload, ['X-Fake-Signature' => 'bad']);
})->throws(MediaWebhookSignatureException::class);

it('is idempotent for a duplicate webhook delivery', function () {
    Event::fake([MediaReady::class]);
    $asset = MediaAsset::factory()->processing()->create();
    [$payload, $headers] = fakeWebhook([
        'id' => 'evt-dup', 'type' => 'ready', 'provider_ref' => $asset->provider_ref, 'status' => 'ready',
    ]);

    $this->svc->processWebhook(MediaProvider::Fake, $payload, $headers);
    $this->svc->processWebhook(MediaProvider::Fake, $payload, $headers); // replay

    expect(MediaWebhookEvent::where('provider_event_id', 'evt-dup')->count())->toBe(1)
        ->and($asset->refresh()->status)->toBe(MediaStatus::Ready);
    Event::assertDispatchedTimes(MediaReady::class, 1);
});

it('drops an out-of-order webhook that would move the asset backwards', function () {
    $asset = MediaAsset::factory()->ready()->create();
    [$payload, $headers] = fakeWebhook([
        'id' => 'evt-late', 'type' => 'processing', 'provider_ref' => $asset->provider_ref, 'status' => 'processing',
    ]);

    $this->svc->processWebhook(MediaProvider::Fake, $payload, $headers);

    // Ready -> Processing is not an allowed transition; the asset stays ready.
    expect($asset->refresh()->status)->toBe(MediaStatus::Ready);
});

it('transitions to failed on a failure webhook', function () {
    Event::fake([MediaFailed::class]);
    $asset = MediaAsset::factory()->processing()->create();
    [$payload, $headers] = fakeWebhook([
        'id' => 'evt-fail', 'type' => 'errored', 'provider_ref' => $asset->provider_ref,
        'status' => 'failed', 'failure_code' => 'transcode', 'failure_message' => 'bad codec',
    ]);

    $this->svc->processWebhook(MediaProvider::Fake, $payload, $headers);

    expect($asset->refresh()->status)->toBe(MediaStatus::Failed)
        ->and($asset->failure_code)->toBe('transcode');
    Event::assertDispatched(MediaFailed::class);
});

it('retries a failed asset without creating a new remote asset', function () {
    $asset = MediaAsset::factory()->failed()->create();
    $originalRef = $asset->provider_ref;

    $updated = $this->svc->retry($asset);

    // The fake provider re-verifies the SAME ref as ready; ref is unchanged (no new upload).
    expect($updated->provider_ref)->toBe($originalRef)
        ->and($updated->status)->toBe(MediaStatus::Ready)
        ->and($updated->failure_code)->toBeNull();
});

it('refuses to retry an asset that is not failed', function () {
    $asset = MediaAsset::factory()->ready()->create();

    $this->svc->retry($asset);
})->throws(MediaTransitionException::class);
