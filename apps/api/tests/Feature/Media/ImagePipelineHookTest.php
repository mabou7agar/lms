<?php

use App\Platform\Media\Events\MediaReady;
use App\Platform\Media\Jobs\GenerateImageVariantsJob;
use App\Platform\Media\Models\MediaAsset;
use App\Platform\Shared\Media\Enums\MediaProvider;
use App\Platform\Shared\Media\Enums\MediaPurpose;
use App\Platform\Shared\Media\Enums\MediaStatus;
use App\Platform\Shared\Media\Enums\MediaType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;

uses(RefreshDatabase::class);

/*
 | D6 - The additive ingestion hook: MediaReady (dispatched unchanged by MediaIngestionService) queues
 | GenerateImageVariantsJob for image assets, and only image assets.
 |
 | We use Bus::fake (not Queue::fake) because the job is afterCommit; under RefreshDatabase's wrapping
 | transaction a Queue::fake dispatch would be deferred until a commit that never happens, whereas
 | Bus::fake records the dispatch intent immediately — which is exactly what this hook test asserts.
 */

function d6AssetOfType(string $type, string $purpose): MediaAsset
{
    return MediaAsset::factory()->create([
        'type' => $type,
        'status' => MediaStatus::Ready->value,
        'provider' => MediaProvider::S3->value,
        'purpose' => $purpose,
        'storage_key' => 'media/'.$type.'/object',
    ]);
}

it('queues variant generation when an image asset becomes Ready', function () {
    Bus::fake();
    $asset = d6AssetOfType(MediaType::Image->value, MediaPurpose::LessonImage->value);

    MediaReady::dispatch((string) $asset->public_id);

    Bus::assertDispatched(GenerateImageVariantsJob::class, function (GenerateImageVariantsJob $job) use ($asset): bool {
        return $job->mediaAssetId === $asset->id;
    });
});

it('does not queue variant generation for a non-image asset becoming Ready', function () {
    Bus::fake();
    $asset = d6AssetOfType(MediaType::Video->value, MediaPurpose::LessonVideo->value);

    MediaReady::dispatch((string) $asset->public_id);

    Bus::assertNotDispatched(GenerateImageVariantsJob::class);
});

it('does nothing for a MediaReady referencing an unknown asset', function () {
    Bus::fake();

    // A syntactically valid but absent UUID: the lookup resolves to null (public_id is a uuid column,
    // so a non-uuid string would error at the DB rather than exercise the "unknown asset" path).
    MediaReady::dispatch((string) \Illuminate\Support\Str::uuid());

    Bus::assertNotDispatched(GenerateImageVariantsJob::class);
});
