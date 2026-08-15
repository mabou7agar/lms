<?php

use App\Platform\Media\Ingestion\Providers\LocalIngestionProvider;
use App\Platform\Media\Jobs\GenerateImageVariantsJob;
use App\Platform\Media\Services\MediaAdminUploadService;
use App\Platform\Shared\Media\Contracts\MediaPickerPort;
use App\Platform\Shared\Media\Enums\MediaProvider;
use App\Platform\Shared\Media\Enums\MediaPurpose;
use App\Platform\Shared\Media\Enums\MediaStatus;
use App\Platform\Shared\Media\Enums\MediaType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    // Select the dev local store and give it a faked disk so no real files are written.
    config()->set('media.ingestion.default', 'local');
    config()->set('media.local.disk', 'media_local');
    Storage::fake('media_local');
    // If any HTTP is attempted the test must fail loudly — the local path must never touch the network.
    Http::preventStrayRequests();
    // Finalize -> MediaReady queues async image-variant generation; fake it (its pipeline is covered elsewhere).
    Bus::fake(GenerateImageVariantsJob::class);
    $this->service = app(MediaAdminUploadService::class);
});

it('persists the real uploaded bytes on the local disk and finalizes to Ready without any HTTP', function () {
    $asset = $this->service->upload(
        actorId: 7,
        purpose: MediaPurpose::LessonImage,
        filename: 'avatar.png',
        mimeType: 'image/png',
        sizeBytes: 12,
        contents: 'binary-bytes',
    );

    expect($asset->status)->toBe(MediaStatus::Ready)
        ->and($asset->provider)->toBe(MediaProvider::Local)
        ->and($asset->type)->toBe(MediaType::Image)
        ->and($asset->original_filename)->toBe('avatar.png')
        ->and($asset->storage_key)->not->toBeNull();

    // The bytes actually landed on disk (unlike the fake provider, which stores nothing).
    Storage::disk('media_local')->assertExists($asset->storage_key);
    expect(Storage::disk('media_local')->get($asset->storage_key))->toBe('binary-bytes');
});

it('serves a picked local asset via a plain disk URL (no playback signing)', function () {
    $asset = $this->service->upload(
        actorId: 7,
        purpose: MediaPurpose::LessonImage,
        filename: 'avatar.png',
        mimeType: 'image/png',
        sizeBytes: 12,
        contents: 'binary-bytes',
    );

    $url = app(MediaPickerPort::class)->previewUrl((string) $asset->public_id);

    expect($url)->toBeString()->toContain($asset->storage_key);
});

it('reports a missing object as Failed on verify', function () {
    $provider = new LocalIngestionProvider(['disk' => 'media_local']);

    $status = $provider->verifyUpload('media/lesson_image/does-not-exist.png');

    expect($status->status)->toBe(MediaStatus::Failed)
        ->and($status->failureCode)->toBe('object_missing');
});
