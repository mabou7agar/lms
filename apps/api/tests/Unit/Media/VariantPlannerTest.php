<?php

use App\Platform\Media\Imaging\VariantPlanner;
use App\Platform\Media\Models\MediaAsset;
use App\Platform\Shared\Media\Enums\MediaPurpose;

/*
 | D6 - Pure config translation: purpose -> surface -> ordered variant specs. No GD, no DB.
 */

it('plans the configured variant set for an explicit surface', function () {
    config()->set('media.images.variant_sets.course_thumbnail', [
        'thumbnail' => ['width' => 320, 'height' => 180, 'mode' => 'cover', 'format' => 'webp'],
        'medium' => ['width' => 1280, 'height' => 720, 'mode' => 'cover', 'format' => 'webp'],
    ]);

    $specs = app(VariantPlanner::class)->forSurface('course_thumbnail');

    expect($specs)->toHaveCount(2)
        ->and($specs[0]->key)->toBe('thumbnail')
        ->and($specs[0]->mode)->toBe('cover')
        ->and($specs[0]->width)->toBe(320)
        ->and($specs[1]->key)->toBe('medium');
});

it('falls back to the default set for an unknown surface', function () {
    config()->set('media.images.variant_sets', [
        'default' => ['small' => ['width' => 100, 'height' => 100, 'mode' => 'fit', 'format' => 'png']],
    ]);

    $specs = app(VariantPlanner::class)->forSurface('does_not_exist');

    expect($specs)->toHaveCount(1)
        ->and($specs[0]->key)->toBe('small');
});

it('infers the surface from the asset purpose', function () {
    config()->set('media.images.purpose_surface', ['lesson_image' => 'gallery']);
    config()->set('media.images.variant_sets.gallery', [
        'thumbnail' => ['width' => 400, 'height' => 400, 'mode' => 'cover', 'format' => 'webp'],
    ]);

    $planner = app(VariantPlanner::class);
    $asset = new MediaAsset;
    $asset->forceFill(['purpose' => MediaPurpose::LessonImage->value]);

    $specs = $planner->for($asset);

    expect($specs)->toHaveCount(1)
        ->and($specs[0]->key)->toBe('thumbnail');
});

it('applies a per-format default quality when a variant omits it', function () {
    config()->set('media.images.quality', ['webp' => 77, 'jpeg' => 60, 'avif' => 40]);
    config()->set('media.images.variant_sets', [
        'default' => [
            'a' => ['width' => 10, 'height' => 10, 'mode' => 'fit', 'format' => 'webp'],
            'b' => ['width' => 10, 'height' => 10, 'mode' => 'fit', 'format' => 'jpeg', 'quality' => 91],
        ],
    ]);

    $specs = app(VariantPlanner::class)->forSurface('default');

    expect($specs[0]->quality)->toBe(77)   // from the webp default
        ->and($specs[1]->quality)->toBe(91); // explicit override wins
});
