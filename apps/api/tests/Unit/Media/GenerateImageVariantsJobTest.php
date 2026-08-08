<?php

use App\Platform\Media\Events\MediaVariantsGenerated;
use App\Platform\Media\Imaging\ImageVariantService;
use App\Platform\Media\Jobs\GenerateImageVariantsJob;
use App\Platform\Media\Models\MediaAsset;
use App\Platform\Media\Models\MediaVariant;
use App\Platform\Shared\Audit\AuditLog;
use App\Platform\Shared\Audit\AuditLogger;
use App\Platform\Shared\Media\Enums\MediaProvider;
use App\Platform\Shared\Media\Enums\MediaPurpose;
use App\Platform\Shared\Media\Enums\MediaStatus;
use App\Platform\Shared\Media\Enums\MediaType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    if (! function_exists('imagecreatetruecolor')) {
        $this->markTestSkipped('ext-gd is required for the image pipeline tests.');
    }

    Storage::fake('s3');
    config()->set('media.images.disk', 's3');
    config()->set('media.images.thumbnail_key', 'thumbnail');
    config()->set('media.images.variant_sets', [
        'default' => [
            'thumbnail' => ['width' => 80, 'height' => 80, 'mode' => 'cover', 'format' => 'png'],
        ],
    ]);
    config()->set('media.images.purpose_surface', ['lesson_image' => 'default']);
});

function d6JobImage(int $w, int $h): string
{
    $img = imagecreatetruecolor($w, $h);
    imagefilledrectangle($img, 0, 0, $w, $h, imagecolorallocate($img, 200, 30, 60));
    ob_start();
    imagepng($img);
    $bytes = (string) ob_get_clean();
    imagedestroy($img);

    return $bytes;
}

function d6JobAsset(string $storageKey, string $contents, string $status = 'ready', string $type = 'image'): MediaAsset
{
    if ($contents !== '') {
        Storage::disk('s3')->put($storageKey, $contents);
    }

    return MediaAsset::factory()->create([
        'type' => $type,
        'status' => $status,
        'provider' => MediaProvider::S3->value,
        'purpose' => MediaPurpose::LessonImage->value,
        'storage_key' => $storageKey,
        'mime_type' => 'image/png',
    ]);
}

it('generates variants and audits success on handle', function () {
    Event::fake([MediaVariantsGenerated::class]);
    $asset = d6JobAsset('media/lesson_image/ok.png', d6JobImage(300, 200));

    (new GenerateImageVariantsJob($asset->id))->handle(app(ImageVariantService::class), app(AuditLogger::class));

    expect(MediaVariant::query()->where('media_asset_id', $asset->id)->count())->toBe(1)
        ->and(AuditLog::query()->where('action', 'media.variants_generated')->where('subject_id', $asset->id)->exists())->toBeTrue();

    Event::assertDispatched(MediaVariantsGenerated::class);
});

it('is a no-op for a non-ready asset', function () {
    $asset = d6JobAsset('media/lesson_image/pending.png', d6JobImage(100, 100), MediaStatus::Processing->value);

    (new GenerateImageVariantsJob($asset->id))->handle(app(ImageVariantService::class), app(AuditLogger::class));

    expect(MediaVariant::query()->where('media_asset_id', $asset->id)->count())->toBe(0)
        ->and(AuditLog::query()->where('subject_id', $asset->id)->count())->toBe(0);
});

it('is a no-op for a non-image asset', function () {
    $asset = d6JobAsset('media/video/clip.mp4', 'fake-bytes', MediaStatus::Ready->value, MediaType::Video->value);

    (new GenerateImageVariantsJob($asset->id))->handle(app(ImageVariantService::class), app(AuditLogger::class));

    expect(MediaVariant::query()->where('media_asset_id', $asset->id)->count())->toBe(0);
});

it('audits a permanent rejection without retrying (no throw)', function () {
    $asset = d6JobAsset('media/lesson_image/bad.bin', 'this is not an image');

    // A rejection is deterministic: handle() catches it, records it, and returns cleanly.
    (new GenerateImageVariantsJob($asset->id))->handle(app(ImageVariantService::class), app(AuditLogger::class));

    expect(AuditLog::query()->where('action', 'media.variants_rejected')->where('subject_id', $asset->id)->exists())->toBeTrue()
        ->and(MediaVariant::query()->where('media_asset_id', $asset->id)->count())->toBe(0);
});

it('lets a transient failure propagate for retry', function () {
    // Original missing on disk => ImageProcessingException, which handle() does NOT swallow (retryable).
    $asset = d6JobAsset('media/lesson_image/missing.png', '');

    (new GenerateImageVariantsJob($asset->id))->handle(app(ImageVariantService::class), app(AuditLogger::class));
})->throws(App\Platform\Media\Exceptions\ImageProcessingException::class);

it('dead-letters to the audit trail on failed()', function () {
    $asset = d6JobAsset('media/lesson_image/x.png', d6JobImage(50, 50));

    (new GenerateImageVariantsJob($asset->id))->failed(new RuntimeException('boom'));

    $row = AuditLog::query()->where('action', 'media.variants_failed')->where('subject_id', $asset->id)->first();

    expect($row)->not->toBeNull()
        ->and($row->context['error'] ?? null)->toContain('boom');
});
