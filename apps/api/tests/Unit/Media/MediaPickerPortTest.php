<?php

use App\Platform\Media\Exceptions\MediaAccessDeniedException;
use App\Platform\Media\Exceptions\MediaValidationException;
use App\Platform\Media\Models\MediaAsset;
use App\Platform\Shared\Media\Contracts\MediaPickerPort;
use App\Platform\Shared\Media\Enums\MediaPurpose;
use App\Platform\Shared\Media\Enums\MediaType;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    config()->set('media.ingestion.default', 'fake');
    // The reusable MediaPicker (Shared) reaches Media ONLY through this bound port; the component
    // never imports a Media class. Types/purposes cross the seam as backed-enum ->value STRINGS.
    $this->port = app(MediaPickerPort::class);
});

it('U1 port: accepts an owned asset whose type matches the string filter/purpose', function () {
    $asset = MediaAsset::factory()->ready()->ownedBy(7)->create([
        'type' => MediaType::Image->value,
        'purpose' => MediaPurpose::LessonImage->value,
    ]);

    expect($this->port->isSelectable(
        (string) $asset->public_id,
        actorId: 7,
        acceptedTypes: ['image'],
        purpose: 'lesson_image',
        ownerScope: null,
    ))->toBeTrue();

    // assertSelectable is the throwing form and must not raise for an authorized reference.
    $this->port->assertSelectable((string) $asset->public_id, 7, ['image'], 'lesson_image', null);
});

it('U1 port: refuses an asset the actor does not own (no existence leak)', function () {
    $asset = MediaAsset::factory()->ready()->ownedBy(7)->create([
        'type' => MediaType::Image->value,
    ]);

    expect($this->port->isSelectable((string) $asset->public_id, 999, ['image'], 'lesson_image', null))->toBeFalse();

    $this->port->assertSelectable((string) $asset->public_id, 999, ['image'], 'lesson_image', null);
})->throws(MediaAccessDeniedException::class);

it('U1 port: refuses an owned asset whose type is not in the accepted string list', function () {
    $asset = MediaAsset::factory()->ready()->ownedBy(7)->create([
        'type' => MediaType::Video->value,
    ]);

    $this->port->assertSelectable((string) $asset->public_id, 7, ['image'], null, null);
})->throws(MediaValidationException::class);

it('U1 port: maps the purpose string and refuses a type the purpose does not accept', function () {
    $asset = MediaAsset::factory()->ready()->ownedBy(7)->create([
        'type' => MediaType::Video->value,
    ]);

    // 'lesson_image' accepts only images; the video reference is refused via the mapped purpose.
    $this->port->assertSelectable((string) $asset->public_id, 7, [], 'lesson_image', null);
})->throws(MediaValidationException::class);
