<?php

use App\Platform\Media\Models\MediaAsset;
use App\Platform\Media\Services\MediaAdminUploadService;
use App\Platform\Shared\Media\Enums\MediaPurpose;
use App\Platform\Shared\Media\Enums\MediaStatus;
use App\Platform\Shared\Media\Enums\MediaType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    config()->set('media.ingestion.default', 'fake');
    // The provider byte-push is the only outbound call; stub it so the engine's finalize path runs.
    Http::fake(['*' => Http::response('', 200)]);
    $this->service = app(MediaAdminUploadService::class);
});

it('D5: uploads a single file through the existing engine and finalizes it to Ready', function () {
    $asset = $this->service->upload(
        actorId: 7,
        purpose: MediaPurpose::LessonImage,
        filename: 'logo.png',
        mimeType: 'image/png',
        sizeBytes: 2048,
        contents: 'binary-bytes',
    );

    expect($asset->created_by)->toBe(7)
        ->and($asset->type)->toBe(MediaType::Image)
        ->and($asset->status)->toBe(MediaStatus::Ready)
        ->and(MediaAsset::query()->whereKey($asset->id)->exists())->toBeTrue();
});

it('D4: a bulk upload reports per-file results and one bad file does not fail the batch', function () {
    $files = [
        ['filename' => 'a.png', 'mime_type' => 'image/png', 'size_bytes' => 1024, 'contents' => 'a'],
        // Oversized for the LessonImage purpose (25 MB cap) — the engine rejects THIS file only.
        ['filename' => 'huge.png', 'mime_type' => 'image/png', 'size_bytes' => 26 * 1024 * 1024, 'contents' => 'big'],
        ['filename' => 'c.png', 'mime_type' => 'image/png', 'size_bytes' => 2048, 'contents' => 'c'],
    ];

    $outcomes = $this->service->uploadMany(7, MediaPurpose::LessonImage, $files);

    expect($outcomes)->toHaveCount(3);

    $succeeded = array_values(array_filter($outcomes, fn ($o) => $o->successful));
    $failed = array_values(array_filter($outcomes, fn ($o) => ! $o->successful));

    expect($succeeded)->toHaveCount(2)
        ->and($failed)->toHaveCount(1)
        ->and($failed[0]->filename)->toBe('huge.png')
        ->and($failed[0]->errorMessage)->not->toBeNull();

    // Exactly the two good files became assets; the batch was not aborted by the bad one.
    expect(MediaAsset::query()->where('created_by', 7)->count())->toBe(2);
});
