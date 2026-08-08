<?php

use App\Platform\Identity\Database\Seeders\RolePermissionSeeder;
use App\Platform\Identity\Models\User;
use App\Platform\Media\Enums\MediaVisibility;
use App\Platform\Media\Exceptions\MediaAccessDeniedException;
use App\Platform\Media\Models\MediaAsset;
use App\Platform\Media\Services\MediaVisibilityService;
use App\Platform\Shared\Media\Contracts\PublicAssetUrlResolver;
use App\Platform\Shared\Media\Enums\MediaType;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * P1 - Visibility is set ONLY through the authorized, audited MediaVisibilityService. A client
 * payload can never flip PRIVATE -> PUBLIC without authorization: every entry point authorizes first.
 */
beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);
    $this->service = app(MediaVisibilityService::class);
});

it('lets the asset owner raise visibility to PUBLIC (authorized, audited)', function () {
    $owner = User::factory()->create();
    $asset = MediaAsset::factory()->ready()->create(['created_by' => $owner->id]);

    expect($asset->visibility)->toBe(MediaVisibility::Private);

    $this->service->setVisibility($asset, MediaVisibility::Public, $owner);

    expect($asset->fresh()->visibility)->toBe(MediaVisibility::Public);

    $this->assertDatabaseHas('audit_logs', [
        'action' => 'media.visibility.changed',
        'actor_id' => $owner->id,
    ]);
});

it('rejects a forged/unauthorized PRIVATE -> PUBLIC raise and leaves the asset private', function () {
    $owner = User::factory()->create();
    $stranger = User::factory()->create();
    $asset = MediaAsset::factory()->ready()->create(['created_by' => $owner->id]);

    expect(fn () => $this->service->setVisibility($asset, MediaVisibility::Public, $stranger))
        ->toThrow(MediaAccessDeniedException::class);

    // The asset must remain PRIVATE — the resolver must still hide it in a public context.
    expect($asset->fresh()->visibility)->toBe(MediaVisibility::Private)
        ->and(app(PublicAssetUrlResolver::class)->resolve((string) $asset->public_id))->toBeNull();
});

it('markPublicForOwner publishes an image only for its owner', function () {
    $owner = User::factory()->create();
    $stranger = User::factory()->create();

    $image = MediaAsset::factory()->ready()->create([
        'created_by' => $owner->id,
        'type' => MediaType::Image->value,
    ]);

    // A non-owner can never publish on someone else's behalf.
    expect(fn () => $this->service->markPublicForOwner($image, $stranger->id))
        ->toThrow(MediaAccessDeniedException::class);
    expect($image->fresh()->visibility)->toBe(MediaVisibility::Private);

    // The owner may publish an image.
    $this->service->markPublicForOwner($image, $owner->id);
    expect($image->fresh()->visibility)->toBe(MediaVisibility::Public);
});

it('markPublicForOwner never widens a non-image asset', function () {
    $owner = User::factory()->create();
    $video = MediaAsset::factory()->ready()->create([
        'created_by' => $owner->id,
        'type' => MediaType::Video->value,
    ]);

    $this->service->markPublicForOwner($video, $owner->id);

    // Non-image (e.g. lesson video) stays PRIVATE — stable public delivery is for imagery only.
    expect($video->fresh()->visibility)->toBe(MediaVisibility::Private);
});

it('super_admin may set visibility on any asset', function () {
    $superAdmin = User::factory()->create();
    $superAdmin->assignRole('super_admin');

    $asset = MediaAsset::factory()->ready()->create(['created_by' => User::factory()->create()->id]);

    $this->service->setVisibility($asset, MediaVisibility::Authenticated, $superAdmin);

    expect($asset->fresh()->visibility)->toBe(MediaVisibility::Authenticated);
});
