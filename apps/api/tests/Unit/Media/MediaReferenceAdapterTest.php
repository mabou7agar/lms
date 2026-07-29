<?php

use App\Platform\Media\Exceptions\MediaAccessDeniedException;
use App\Platform\Media\Exceptions\MediaNotReadyException;
use App\Platform\Media\Models\MediaAsset;
use App\Platform\Shared\Media\Contracts\MediaReferencePort;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->port = app(MediaReferencePort::class);
});

it('resolves a client-safe reference by public id', function () {
    $asset = MediaAsset::factory()->ready()->ownedBy(5)->create();

    $ref = $this->port->reference((string) $asset->public_id);

    expect($ref)->not->toBeNull()
        ->and($ref->publicId)->toBe((string) $asset->public_id)
        ->and($ref->ownerActorId)->toBe(5)
        ->and($ref->isReady())->toBeTrue();
});

it('returns null for an unknown asset', function () {
    expect($this->port->reference('00000000-0000-0000-0000-000000000000'))->toBeNull();
});

it('asserts a ready owned asset is usable', function () {
    $asset = MediaAsset::factory()->ready()->ownedBy(5)->create();

    $this->port->assertUsableBy((string) $asset->public_id, 5);
})->throwsNoExceptions();

it('rejects using an asset the actor does not own (as not found)', function () {
    $asset = MediaAsset::factory()->ready()->ownedBy(5)->create();

    $this->port->assertUsableBy((string) $asset->public_id, 999);
})->throws(MediaAccessDeniedException::class);

it('rejects using a non-ready asset', function () {
    $asset = MediaAsset::factory()->processing()->ownedBy(5)->create();

    $this->port->assertUsableBy((string) $asset->public_id, 5);
})->throws(MediaNotReadyException::class);
