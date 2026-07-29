<?php

use App\Platform\Media\Enums\CaptionStatus;
use App\Platform\Media\Exceptions\MediaValidationException;
use App\Platform\Media\Models\MediaAsset;
use App\Platform\Media\Services\MediaCaptionService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->captions = app(MediaCaptionService::class);
});

it('accepts a valid BCP-47 language tag', function () {
    $asset = MediaAsset::factory()->ready()->create();

    $caption = $this->captions->addCaption($asset, 1, 'en-US', 'English (US)', 'vtt', 'captions/en.vtt');

    expect($caption->language)->toBe('en-US')
        ->and($caption->status)->toBe(CaptionStatus::Ready);
});

it('rejects an invalid language tag', function () {
    $asset = MediaAsset::factory()->ready()->create();

    $this->captions->addCaption($asset, 1, 'not_a_language!!', 'Bad');
})->throws(MediaValidationException::class);

it('rejects a second caption for the same language', function () {
    $asset = MediaAsset::factory()->ready()->create();
    $this->captions->addCaption($asset, 1, 'fr', 'Français', 'vtt', 'captions/fr.vtt');

    $this->captions->addCaption($asset, 1, 'fr', 'Français again', 'vtt', 'captions/fr2.vtt');
})->throws(MediaValidationException::class);
