<?php

use App\Platform\Identity\Database\Seeders\RolePermissionSeeder;
use App\Platform\Identity\Models\User;
use App\Platform\Media\Enums\MediaVisibility;
use App\Platform\Media\Exceptions\MediaAccessDeniedException;
use App\Platform\Media\Models\MediaAsset;
use App\Platform\Media\Services\MediaVisibilityService;
use App\Platform\Shared\Media\Enums\MediaType;
use App\Platform\Shared\Tenancy\TenantContext;
use App\Platform\Shared\Tenancy\TenantId;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * T1 Option-N tenant dimension of the ONE authorized visibility path. A raise on an org-owned asset is
 * allowed ONLY under its owning tenant; a GLOBAL asset may be raised in any context (existing behaviour).
 */
beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);
    $this->service = app(MediaVisibilityService::class);
    app(TenantContext::class)->forget();
});

afterEach(function (): void {
    app(TenantContext::class)->forget();
});

it('lets an org1 owner raise their own org1 asset to PUBLIC under org1', function (): void {
    $owner = User::factory()->create();
    $asset = MediaAsset::factory()->ready()->create(['created_by' => $owner->id]);
    $asset->forceFill(['organization_id' => 1])->saveQuietly();

    app(TenantContext::class)->set(TenantId::from(1));

    $this->service->setVisibility($asset, MediaVisibility::Public, $owner);

    expect($asset->fresh()->visibility)->toBe(MediaVisibility::Public);
});

it('denies raising an org2-owned asset while the active tenant is org1 (setVisibility)', function (): void {
    $owner = User::factory()->create();
    $asset = MediaAsset::factory()->ready()->create(['created_by' => $owner->id]);
    $asset->forceFill(['organization_id' => 2])->saveQuietly();

    app(TenantContext::class)->set(TenantId::from(1));

    expect(fn () => $this->service->setVisibility($asset, MediaVisibility::Public, $owner))
        ->toThrow(MediaAccessDeniedException::class);

    expect($asset->fresh()->visibility)->toBe(MediaVisibility::Private);
});

it('denies markPublicForOwner on an org2-owned image while the active tenant is org1', function (): void {
    $owner = User::factory()->create();
    $image = MediaAsset::factory()->ready()->create([
        'created_by' => $owner->id,
        'type' => MediaType::Image->value,
    ]);
    $image->forceFill(['organization_id' => 2])->saveQuietly();

    app(TenantContext::class)->set(TenantId::from(1));

    expect(fn () => $this->service->markPublicForOwner($image, $owner->id))
        ->toThrow(MediaAccessDeniedException::class);

    expect($image->fresh()->visibility)->toBe(MediaVisibility::Private);
});

it('still lets an owner raise a GLOBAL asset with no tenant resolved (backward compatible)', function (): void {
    $owner = User::factory()->create();
    $asset = MediaAsset::factory()->ready()->create(['created_by' => $owner->id]); // organization_id NULL

    app(TenantContext::class)->forget();

    $this->service->setVisibility($asset, MediaVisibility::Public, $owner);

    expect($asset->fresh()->visibility)->toBe(MediaVisibility::Public);
});
