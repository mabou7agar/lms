<?php

use App\Platform\Shared\Filament\Forms\Components\MediaPicker;

it('defaults to the image editor on, no circle crop, no forced ratios', function () {
    $picker = MediaPicker::make('photo');

    expect($picker->hasImageEditor())->toBeTrue()
        ->and($picker->isCircleCrop())->toBeFalse()
        ->and($picker->getImageAspectRatios())->toBeNull();
});

it('enables a circular crop when requested', function () {
    $picker = MediaPicker::make('avatar')->circleCrop();

    expect($picker->isCircleCrop())->toBeTrue();
});

it('carries explicit editor aspect ratios', function () {
    $picker = MediaPicker::make('thumbnail')->imageAspectRatios(['16:9']);

    expect($picker->getImageAspectRatios())->toBe(['16:9']);
});

it('can turn the editor off', function () {
    $picker = MediaPicker::make('file')->imageEditor(false);

    expect($picker->hasImageEditor())->toBeFalse();
});
