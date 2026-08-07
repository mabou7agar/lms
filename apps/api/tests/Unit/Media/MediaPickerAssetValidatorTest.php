<?php

use App\Platform\Media\Exceptions\MediaAccessDeniedException;
use App\Platform\Media\Exceptions\MediaValidationException;
use App\Platform\Media\Models\MediaAsset;
use App\Platform\Media\Services\MediaPickerAssetValidator;
use App\Platform\Shared\Filament\Forms\Components\MediaPicker;
use App\Platform\Shared\Media\Enums\MediaPurpose;
use App\Platform\Shared\Media\Enums\MediaType;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    config()->set('media.ingestion.default', 'fake');
    $this->validator = app(MediaPickerAssetValidator::class);
});

it('U1: resolves an owned, accepted asset and yields its public id as the stored reference', function () {
    $asset = MediaAsset::factory()->ready()->ownedBy(7)->create([
        'type' => MediaType::Image->value,
        'purpose' => MediaPurpose::LessonImage->value,
    ]);

    $reference = $this->validator->validate(
        (string) $asset->public_id,
        actorId: 7,
        acceptedTypes: [MediaType::Image],
        purpose: MediaPurpose::LessonImage,
    );

    // The stored reference is the asset's public_id — the identity the app references assets by,
    // never a raw URL/storage key.
    expect($reference->publicId)->toBe((string) $asset->public_id)
        ->and($reference->type)->toBe(MediaType::Image)
        ->and($reference->ownerActorId)->toBe(7);
});

it('U1: rejects an asset id the actor does not own (no existence leak)', function () {
    $asset = MediaAsset::factory()->ready()->ownedBy(7)->create([
        'type' => MediaType::Image->value,
    ]);

    // Actor 999 does not own it: indistinguishable from "not found".
    $this->validator->validate((string) $asset->public_id, actorId: 999, acceptedTypes: [MediaType::Image]);
})->throws(MediaAccessDeniedException::class);

it('U1: rejects a completely unknown asset id', function () {
    $this->validator->validate('00000000-0000-0000-0000-000000000000', actorId: 7);
})->throws(MediaAccessDeniedException::class);

it('U1: rejects an owned asset whose type is not accepted by the field', function () {
    $asset = MediaAsset::factory()->ready()->ownedBy(7)->create([
        'type' => MediaType::Video->value,
    ]);

    // The field only accepts images; a video reference is refused.
    $this->validator->validate((string) $asset->public_id, actorId: 7, acceptedTypes: [MediaType::Image]);
})->throws(MediaValidationException::class);

it('U1: rejects an owned asset whose type is not valid for the bound purpose', function () {
    $asset = MediaAsset::factory()->ready()->ownedBy(7)->create([
        'type' => MediaType::Video->value,
    ]);

    // LessonImage only accepts images; the picker's purpose guard refuses the video.
    $this->validator->validate((string) $asset->public_id, actorId: 7, purpose: MediaPurpose::LessonImage);
})->throws(MediaValidationException::class);

it('U1: an owner scope that does not match is refused (tenant-ready hook)', function () {
    $asset = MediaAsset::factory()->ready()->ownedBy(7)->create(['type' => MediaType::Image->value]);

    // Actor owns it, but the explicit owner scope points elsewhere.
    $this->validator->validate((string) $asset->public_id, actorId: 7, ownerScope: 8);
})->throws(MediaAccessDeniedException::class);

it('U1 dual-read: classifyValue distinguishes empty, a reference (uuid) and a legacy URL', function () {
    $asset = MediaAsset::factory()->ready()->ownedBy(7)->create();

    expect(MediaPicker::classifyValue(null))->toBe('empty')
        ->and(MediaPicker::classifyValue(''))->toBe('empty')
        ->and(MediaPicker::classifyValue('   '))->toBe('empty')
        ->and(MediaPicker::classifyValue((string) $asset->public_id))->toBe('reference')
        ->and(MediaPicker::classifyValue('https://cdn.example.test/legacy/banner.png'))->toBe('legacy')
        ->and(MediaPicker::classifyValue('/storage/old/path.pdf'))->toBe('legacy');
});
