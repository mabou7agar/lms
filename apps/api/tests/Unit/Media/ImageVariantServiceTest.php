<?php

use App\Platform\Media\Exceptions\ImageProcessingException;
use App\Platform\Media\Exceptions\ImageRejectedException;
use App\Platform\Media\Imaging\ImageVariantService;
use App\Platform\Media\Models\MediaAsset;
use App\Platform\Media\Models\MediaVariant;
use App\Platform\Shared\Media\Enums\MediaProvider;
use App\Platform\Shared\Media\Enums\MediaPurpose;
use App\Platform\Shared\Media\Enums\MediaStatus;
use App\Platform\Shared\Media\Enums\MediaType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    if (! function_exists('imagecreatetruecolor')) {
        $this->markTestSkipped('ext-gd is required for the image pipeline tests.');
    }

    // Variants + original live on a fake disk; a small PNG set keeps the test codec-independent.
    Storage::fake('s3');
    config()->set('media.images.disk', 's3');
    config()->set('media.images.thumbnail_key', 'thumbnail');
    config()->set('media.images.variant_sets', [
        'default' => [
            'thumbnail' => ['width' => 100, 'height' => 100, 'mode' => 'cover', 'format' => 'png'],
            'medium' => ['width' => 200, 'height' => 200, 'mode' => 'fit', 'format' => 'png'],
        ],
    ]);
    config()->set('media.images.purpose_surface', ['lesson_image' => 'default']);
});

/** A tiny solid PNG raster. */
function d6ServiceImage(int $width, int $height): string
{
    $img = imagecreatetruecolor($width, $height);
    imagefilledrectangle($img, 0, 0, $width, $height, imagecolorallocate($img, 20, 140, 90));
    ob_start();
    imagepng($img);
    $bytes = (string) ob_get_clean();
    imagedestroy($img);

    return $bytes;
}

/** Create a Ready image asset whose original already sits on the fake disk. */
function d6ReadyImageAsset(string $originalKey = 'media/lesson_image/original.png', int $w = 400, int $h = 300): MediaAsset
{
    $original = d6ServiceImage($w, $h);
    Storage::disk('s3')->put($originalKey, $original);

    return MediaAsset::factory()->create([
        'type' => MediaType::Image->value,
        'status' => MediaStatus::Ready->value,
        'provider' => MediaProvider::S3->value,
        'purpose' => MediaPurpose::LessonImage->value,
        'storage_key' => $originalKey,
        'mime_type' => 'image/png',
        'width' => $w,
        'height' => $h,
        'thumbnail_ref' => null,
    ]);
}

it('creates media_variants rows with unique keys and exact dimensions', function () {
    $asset = d6ReadyImageAsset();

    $variants = app(ImageVariantService::class)->generate($asset);

    expect($variants)->toHaveCount(2);

    $rows = MediaVariant::query()->where('media_asset_id', $asset->id)->orderBy('variant_key')->get();
    expect($rows->pluck('variant_key')->all())->toBe(['medium', 'thumbnail'])
        ->and($rows->pluck('variant_key')->unique()->count())->toBe(2);

    $thumbnail = $rows->firstWhere('variant_key', 'thumbnail');
    expect($thumbnail->width)->toBe(100)->and($thumbnail->height)->toBe(100)
        ->and($thumbnail->format)->toBe('png')
        ->and($thumbnail->size_bytes)->toBeGreaterThan(0);
});

it('preserves the original object unchanged after processing', function () {
    $key = 'media/lesson_image/keepme.png';
    $original = d6ServiceImage(400, 300);
    Storage::disk('s3')->put($key, $original);

    $asset = MediaAsset::factory()->create([
        'type' => MediaType::Image->value,
        'status' => MediaStatus::Ready->value,
        'provider' => MediaProvider::S3->value,
        'purpose' => MediaPurpose::LessonImage->value,
        'storage_key' => $key,
        'mime_type' => 'image/png',
        'width' => 400,
        'height' => 300,
    ]);

    app(ImageVariantService::class)->generate($asset);

    // Byte-for-byte identical: the pipeline only ever writes NEW variant objects.
    expect(Storage::disk('s3')->get($key))->toBe($original);
});

it('writes each variant as a new storage object distinct from the original key', function () {
    $asset = d6ReadyImageAsset();

    $variants = app(ImageVariantService::class)->generate($asset);

    foreach ($variants as $variant) {
        expect(Storage::disk('s3')->exists($variant->storage_key))->toBeTrue()
            ->and($variant->storage_key)->not->toBe($asset->storage_key);
    }
});

it('populates the previously-unused thumbnail_ref from the thumbnail variant', function () {
    $asset = d6ReadyImageAsset();

    app(ImageVariantService::class)->generate($asset);

    $thumb = MediaVariant::query()->where('media_asset_id', $asset->id)->where('variant_key', 'thumbnail')->firstOrFail();

    expect($asset->refresh()->thumbnail_ref)->toBe($thumb->storage_key);
});

it('is idempotent: regenerating upserts the same rows rather than duplicating', function () {
    $asset = d6ReadyImageAsset();
    $service = app(ImageVariantService::class);

    $service->generate($asset);
    $service->generate($asset); // re-run

    expect(MediaVariant::query()->where('media_asset_id', $asset->id)->count())->toBe(2);
});

it('rejects a non-image original permanently', function () {
    $key = 'media/lesson_image/not-image.bin';
    Storage::disk('s3')->put($key, 'not an image at all');

    $asset = MediaAsset::factory()->create([
        'type' => MediaType::Image->value,
        'status' => MediaStatus::Ready->value,
        'provider' => MediaProvider::S3->value,
        'purpose' => MediaPurpose::LessonImage->value,
        'storage_key' => $key,
    ]);

    app(ImageVariantService::class)->generate($asset);
})->throws(ImageRejectedException::class);

it('raises a processing error when the original object is missing', function () {
    $asset = MediaAsset::factory()->create([
        'type' => MediaType::Image->value,
        'status' => MediaStatus::Ready->value,
        'provider' => MediaProvider::S3->value,
        'purpose' => MediaPurpose::LessonImage->value,
        'storage_key' => 'media/lesson_image/missing.png',
    ]);

    app(ImageVariantService::class)->generate($asset);
})->throws(ImageProcessingException::class);
