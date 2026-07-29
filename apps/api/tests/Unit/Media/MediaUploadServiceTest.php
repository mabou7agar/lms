<?php

use App\Platform\Media\Events\MediaUploadCreated;
use App\Platform\Media\Exceptions\MediaValidationException;
use App\Platform\Media\Services\MediaUploadService;
use App\Platform\Shared\Media\Enums\MediaPurpose;
use App\Platform\Shared\Media\Enums\MediaStatus;
use App\Platform\Shared\Media\Enums\MediaType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;

uses(RefreshDatabase::class);

beforeEach(function () {
    config()->set('media.ingestion.default', 'fake');
});

function createUpload(array $overrides = [])
{
    return app(MediaUploadService::class)->createDirectUpload(
        actorId: $overrides['actorId'] ?? 1,
        type: $overrides['type'] ?? MediaType::Video,
        purpose: $overrides['purpose'] ?? MediaPurpose::LessonVideo,
        filename: $overrides['filename'] ?? 'lecture.mp4',
        mimeType: $overrides['mimeType'] ?? 'video/mp4',
        sizeBytes: $overrides['sizeBytes'] ?? 10 * 1024 * 1024,
        courseId: $overrides['courseId'] ?? null,
        idempotencyKey: $overrides['idempotencyKey'] ?? 'key-'.uniqid(),
    );
}

it('creates a direct upload with an opaque url and a single-use token', function () {
    Event::fake([MediaUploadCreated::class]);

    $ticket = createUpload();

    expect($ticket->asset->status)->toBe(MediaStatus::WaitingForUpload)
        ->and($ticket->uploadToken)->not->toBe('')
        ->and($ticket->instructions->uploadUrl)->toContain('upload.fake.test')
        ->and($ticket->asset->provider_ref)->not->toBeNull();

    Event::assertDispatched(MediaUploadCreated::class);
});

it('rejects a media type the purpose does not accept', function () {
    createUpload(['type' => MediaType::Document, 'purpose' => MediaPurpose::LessonVideo]);
})->throws(MediaValidationException::class);

it('rejects a file larger than the purpose allows', function () {
    // LessonImage caps at 25 MB.
    createUpload([
        'type' => MediaType::Image,
        'purpose' => MediaPurpose::LessonImage,
        'mimeType' => 'image/png',
        'sizeBytes' => 30 * 1024 * 1024,
    ]);
})->throws(MediaValidationException::class);

it('is idempotent for the same actor + idempotency key', function () {
    $first = createUpload(['idempotencyKey' => 'stable-key']);
    $second = createUpload(['idempotencyKey' => 'stable-key']);

    expect($second->asset->id)->toBe($first->asset->id);
});
