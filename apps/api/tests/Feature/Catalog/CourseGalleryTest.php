<?php

use App\Domains\Catalog\Models\Course;
use App\Domains\Catalog\Models\CourseGalleryItem;
use App\Platform\Media\Models\MediaAsset;
use App\Platform\Shared\Media\Enums\MediaPurpose;
use App\Platform\Shared\Media\Enums\MediaType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

it('returns gallery items ordered by position', function () {
    $course = Course::factory()->create();

    // Insert out of order; the relation must return them by ascending position.
    $course->galleryItems()->create(['media_public_id' => 'ref-c', 'position' => 3]);
    $course->galleryItems()->create(['media_public_id' => 'ref-a', 'position' => 1]);
    $course->galleryItems()->create(['media_public_id' => 'ref-b', 'position' => 2]);

    $positions = $course->galleryItems()->pluck('position')->all();
    $refs = $course->galleryItems()->pluck('media_public_id')->all();

    expect($positions)->toBe([1, 2, 3])
        ->and($refs)->toBe(['ref-a', 'ref-b', 'ref-c']);
});

it('deletes a gallery item without ever touching the shared media asset', function () {
    $course = Course::factory()->create();

    $asset = MediaAsset::factory()->ready()->create([
        'type' => MediaType::Image->value,
        'purpose' => MediaPurpose::LessonImage->value,
    ]);

    $item = $course->galleryItems()->create([
        'media_public_id' => (string) $asset->public_id,
        'position' => 1,
    ]);

    $item->delete();

    // The ordering row is gone; the asset it referenced survives untouched.
    expect(CourseGalleryItem::query()->whereKey($item->id)->exists())->toBeFalse()
        ->and(MediaAsset::query()->whereKey($asset->id)->exists())->toBeTrue();
});

it('cascades gallery rows when the parent course is force-deleted', function () {
    $course = Course::factory()->create();
    $course->galleryItems()->create(['media_public_id' => 'ref-a', 'position' => 1]);
    $course->galleryItems()->create(['media_public_id' => 'ref-b', 'position' => 2]);

    $course->forceDelete();

    expect(DB::table('course_gallery_items')->where('course_id', $course->id)->count())->toBe(0);
});
