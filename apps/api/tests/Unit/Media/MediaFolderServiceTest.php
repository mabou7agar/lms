<?php

use App\Platform\Media\Exceptions\MediaValidationException;
use App\Platform\Media\Models\MediaAsset;
use App\Platform\Media\Models\MediaFolder;
use App\Platform\Media\Services\MediaFolderService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    config()->set('media.ingestion.default', 'fake');
    $this->folders = app(MediaFolderService::class);
});

it('D1: creates, renames and moves a folder', function () {
    $parent = $this->folders->create('Marketing', actorId: 7);
    $child = $this->folders->create('Banners', actorId: 7);

    expect($parent->name)->toBe('Marketing')
        ->and($parent->created_by)->toBe(7)
        ->and($child->parent_id)->toBeNull();

    $this->folders->rename($child, 'Hero Banners', 7);
    $this->folders->move($child, $parent, 7);

    $child->refresh();
    expect($child->name)->toBe('Hero Banners')
        ->and($child->parent_id)->toBe($parent->id);

    // Move back to root.
    $this->folders->move($child, null, 7);
    expect($child->refresh()->parent_id)->toBeNull();
});

it('D1: deleting a folder keeps its assets (reassigned to root) and reparents its children', function () {
    $parent = MediaFolder::factory()->ownedBy(7)->create();
    $folder = MediaFolder::factory()->ownedBy(7)->childOf($parent)->create();
    $child = MediaFolder::factory()->ownedBy(7)->childOf($folder)->create();

    $assetA = MediaAsset::factory()->ready()->ownedBy(7)->inFolder($folder->id)->create();
    $assetB = MediaAsset::factory()->ready()->ownedBy(7)->inFolder($folder->id)->create();

    $this->folders->delete($folder, 7);

    // The folder is gone...
    expect(MediaFolder::query()->whereKey($folder->id)->exists())->toBeFalse();

    // ...but its assets survive and fall back to root (folder_id = null).
    expect(MediaAsset::query()->whereKey($assetA->id)->exists())->toBeTrue()
        ->and($assetA->refresh()->folder_id)->toBeNull()
        ->and($assetB->refresh()->folder_id)->toBeNull();

    // ...and its child folder survives, reparented to the deleted folder's parent.
    expect(MediaFolder::query()->whereKey($child->id)->exists())->toBeTrue()
        ->and($child->refresh()->parent_id)->toBe($parent->id);
});

it('D1: refuses to move a folder inside itself', function () {
    $folder = MediaFolder::factory()->ownedBy(7)->create();

    $this->folders->move($folder, $folder, 7);
})->throws(MediaValidationException::class);

it('D1: refuses to move a folder inside one of its own descendants (cycle guard)', function () {
    $root = MediaFolder::factory()->ownedBy(7)->create();
    $child = MediaFolder::factory()->ownedBy(7)->childOf($root)->create();

    // Moving the root under its own child would create a cycle.
    $this->folders->move($root, $child, 7);
})->throws(MediaValidationException::class);

it('D1: assigns and clears an asset folder', function () {
    $folder = MediaFolder::factory()->ownedBy(7)->create();
    $asset = MediaAsset::factory()->ready()->ownedBy(7)->create();

    $this->folders->assignAsset($asset, $folder, 7);
    expect($asset->refresh()->folder_id)->toBe($folder->id);

    $this->folders->assignAsset($asset, null, 7);
    expect($asset->refresh()->folder_id)->toBeNull();
});
