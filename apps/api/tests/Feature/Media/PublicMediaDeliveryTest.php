<?php

use App\Platform\Media\Enums\MediaVisibility;
use App\Platform\Media\Jobs\GenerateImageVariantsJob;
use App\Platform\Media\Models\MediaAsset;
use App\Platform\Shared\Media\Contracts\MediaPickerPort;
use App\Platform\Shared\Media\Contracts\PublicAssetUrlResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    config()->set('media.ingestion.default', 'local');
    config()->set('media.local.disk', 'media_local');
    Storage::fake('media_local');
    Http::preventStrayRequests();
    Bus::fake(GenerateImageVariantsJob::class);
});

it('publishes a picker-uploaded image and serves it over the stable public URL', function () {
    $publicId = app(MediaPickerPort::class)->upload(
        actorId: 7,
        purpose: 'lesson_image',
        filename: 'avatar.png',
        mimeType: 'image/png',
        sizeBytes: 12,
        contents: 'binary-bytes',
    );

    // The picker now publishes uploaded images so a public renderer can resolve them.
    $asset = MediaAsset::query()->where('public_id', $publicId)->firstOrFail();
    expect($asset->visibility)->toBe(MediaVisibility::Public);

    // The public resolver returns the stable /media/public/{id} URL (not null, not a signed token).
    $url = app(PublicAssetUrlResolver::class)->resolve($publicId);
    expect($url)->toBeString()->toContain('/media/public/'.$publicId);

    // And that URL actually serves the stored bytes (buffered public delivery — see PublicMediaController).
    $response = $this->get('/media/public/'.$publicId);
    $response->assertOk();
    expect($response->getContent())->toBe('binary-bytes');
});

it('404s for a missing public id', function () {
    $this->get('/media/public/'.Str::uuid())->assertNotFound();
});

it('never serves a private asset over the public route', function () {
    Storage::disk('media_local')->put('media/lesson_image/secret.png', 'secret-bytes');

    $asset = new MediaAsset;
    $asset->forceFill([
        'type' => 'image',
        'status' => 'ready',
        'provider' => 'local',
        'purpose' => 'lesson_image',
        'visibility' => 'private',
        'created_by' => 7,
        'organization_id' => null,
        'original_filename' => 'secret.png',
        'mime_type' => 'image/png',
        'size_bytes' => 12,
        'storage_key' => 'media/lesson_image/secret.png',
        'idempotency_key' => (string) Str::uuid(),
    ])->save();

    $this->get('/media/public/'.$asset->public_id)->assertNotFound();
});
