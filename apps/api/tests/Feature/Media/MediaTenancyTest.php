<?php

use App\Platform\Media\Models\MediaAsset;
use App\Platform\Media\Models\MediaFolder;
use App\Platform\Media\Models\MediaVariant;
use App\Platform\Media\Services\MediaFolderService;
use App\Platform\Shared\Media\Contracts\MediaReferencePort;
use App\Platform\Shared\Media\Contracts\PublicAssetUrlResolver;
use App\Platform\Shared\Tenancy\TenantContext;
use App\Platform\Shared\Tenancy\TenantId;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * T1 Option-N ("global-OR-own-org") adversarial matrix for Media (media_assets = SHARED-OR-OWNED /
 * NULLABLE). NULL organization_id = GLOBAL public/catalog asset (renders for everyone incl. anonymous);
 * a non-null organization_id = an org-PRIVATE asset that must NEVER resolve or be signed for another
 * tenant. Variants/attachments/folders follow the asset transitively.
 *
 * These are the ONLY Media tests that establish a tenant context; the existing Media suite runs with
 * NULL-org users so SharedOrOwnedTenantScope no-ops there and those tests stay behaviourally identical.
 * The tenant is only ever derived server-side (resolved TenantContext) — never from client input.
 */
beforeEach(function (): void {
    config()->set('learning.playback.provider', 'fake');
    config()->set('media.public.base_url', 'https://cdn.test');

    $this->resolver = app(PublicAssetUrlResolver::class);

    app(TenantContext::class)->forget();
});

afterEach(function (): void {
    app(TenantContext::class)->forget();
});

/** Act as the given tenant (null = anonymous / no tenant resolved). */
function asTenant(?int $org): void
{
    $context = app(TenantContext::class);

    if ($org === null) {
        $context->forget();

        return;
    }

    $context->set(TenantId::from($org));
}

// ---------------------------------------------------------------------------------------------
// Resolution isolation: an org-PRIVATE asset never resolves/signs for a different tenant.
// ---------------------------------------------------------------------------------------------

it('never resolves an org2 PRIVATE asset for org1 (scoped out => null)', function (): void {
    $asset = MediaAsset::factory()->ready()->organization(2)->create();

    asTenant(1);

    expect($this->resolver->resolve((string) $asset->public_id))->toBeNull();
});

it('never resolves an org2 PRIVATE public_id through the cross-context MediaReferencePort for org1', function (): void {
    $asset = MediaAsset::factory()->ready()->organization(2)->create();

    asTenant(1);

    // The safe cross-context seam Authoring/Assessment use rides the same scope: an org2 public_id is
    // simply not found under org1 -> null (no existence leak).
    expect(app(MediaReferencePort::class)->reference((string) $asset->public_id))->toBeNull();
});

it('treats an org-scoped PUBLIC asset as tenant-bound: never public for anonymous or a different tenant', function (): void {
    // An org-owned asset an admin raised to PUBLIC is NOT internet-public. Default safely.
    $asset = MediaAsset::factory()->ready()->publicVisibility()->organization(2)->create();

    asTenant(null); // anonymous
    expect($this->resolver->resolve((string) $asset->public_id))->toBeNull();

    asTenant(1); // a different tenant
    expect($this->resolver->resolve((string) $asset->public_id))->toBeNull();
});

it('resolves an org-scoped PUBLIC asset ONLY for its owning tenant, and as a SIGNED (not stable public) URL', function (): void {
    $asset = MediaAsset::factory()->ready()->publicVisibility()->organization(2)->create();

    asTenant(2);

    $url = $this->resolver->resolve((string) $asset->public_id);

    expect($url)->toBeString()
        ->and($url)->toContain('/media/stream/')   // tenant-bound signed URL
        ->and($url)->toContain('expires=')          // short-lived
        ->and($url)->not->toContain('/media/public/'); // NOT the stable, unauthenticated CDN URL
});

// ---------------------------------------------------------------------------------------------
// GLOBAL public assets still render normally — for a resolved tenant AND for anonymous.
// ---------------------------------------------------------------------------------------------

it('renders a GLOBAL PUBLIC asset as a stable CDN URL for anonymous and for a resolved tenant', function (): void {
    $asset = MediaAsset::factory()->ready()->publicVisibility()->create(); // organization_id NULL = global

    // Anonymous.
    asTenant(null);
    $anon = $this->resolver->resolve((string) $asset->public_id);

    // A resolved (unrelated) tenant.
    asTenant(1);
    $tenant = $this->resolver->resolve((string) $asset->public_id);

    expect($anon)->toBeString()
        ->and($anon)->toContain('https://cdn.test/media/public/')
        ->and($anon)->toContain('?v=')
        ->and($anon)->not->toContain('expires=')
        ->and($tenant)->toBe($anon); // identical, stable, cacheable for everyone
});

it('keeps a GLOBAL asset visible (isGlobal) under a resolved tenant', function (): void {
    $global = MediaAsset::factory()->ready()->create();

    asTenant(1);

    $found = MediaAsset::query()->where('public_id', $global->public_id)->first();

    expect($found)->not->toBeNull()
        ->and($found->isGlobal())->toBeTrue();
});

// ---------------------------------------------------------------------------------------------
// Model-boundary isolation: an org1 tenant sees global + org1, never org2.
// ---------------------------------------------------------------------------------------------

it('shows an org1 tenant the global assets PLUS org1-private, never org2-private (model boundary)', function (): void {
    MediaAsset::factory()->create(); // global
    MediaAsset::factory()->organization(1)->create();
    $org2 = MediaAsset::factory()->organization(2)->create();

    asTenant(1);

    expect(MediaAsset::count())->toBe(2)
        ->and(MediaAsset::whereKey($org2->getKey())->exists())->toBeFalse();
});

// ---------------------------------------------------------------------------------------------
// Server-side stamping + forged-tenant defense.
// ---------------------------------------------------------------------------------------------

it('stamps organization_id from the resolved tenant on create, and leaves it NULL (global) with no tenant', function (): void {
    asTenant(1);
    $owned = MediaAsset::factory()->create();
    expect((int) $owned->organization_id)->toBe(1);

    asTenant(null);
    $global = MediaAsset::factory()->create();
    expect($global->organization_id)->toBeNull()
        ->and($global->isGlobal())->toBeTrue();
});

it('ignores a forged organization_id in a create payload (not fillable) and stamps the real tenant', function (): void {
    asTenant(1);

    // Created under org1: the trait stamps the resolved tenant server-side.
    $asset = MediaAsset::factory()->create();
    expect((int) $asset->organization_id)->toBe(1);

    // organization_id is $guarded: a forged value via mass-assignment (the real payload path) is
    // dropped, so the asset stays owned by org1. (Factories bypass mass-assignment guards, so a
    // factory attribute is NOT a valid stand-in for a forged request payload.)
    $asset->update(['organization_id' => 2]);
    expect((int) $asset->fresh()->organization_id)->toBe(1);
});

// ---------------------------------------------------------------------------------------------
// Replacement keeps the correct tenant (a repointed reference stays tenant-private).
// ---------------------------------------------------------------------------------------------

it('mints a replacement asset under the same tenant, keeping it org-private (never visible to another org)', function (): void {
    // A "replace" mints a NEW asset; created under org1 it is stamped org1 by the same trait, so the
    // repointed reference resolves only for org1 and never leaks to org2.
    asTenant(1);
    $replacement = MediaAsset::factory()->ready()->create();

    expect((int) $replacement->organization_id)->toBe(1)
        ->and($replacement->belongsToTenant(TenantId::from(1)))->toBeTrue();

    // Under org2 the repointed asset is simply invisible and never resolves.
    asTenant(2);
    expect(MediaAsset::whereKey($replacement->getKey())->exists())->toBeFalse()
        ->and($this->resolver->resolve((string) $replacement->public_id))->toBeNull();
});

// ---------------------------------------------------------------------------------------------
// Variants inherit the asset's tenant transitively (no own tenant column).
// ---------------------------------------------------------------------------------------------

it('hides an org2 asset variant from org1 when scoped through its asset (transitive tenancy)', function (): void {
    $asset = MediaAsset::factory()->ready()->organization(2)->create();
    $variant = MediaVariant::factory()->create(['media_asset_id' => $asset->getKey()]);

    asTenant(1);

    // The asset itself is scoped out for org1...
    expect(MediaAsset::whereKey($asset->getKey())->exists())->toBeFalse()
        // ...so any variant listing that scopes through the asset (the required pattern) excludes it.
        ->and(MediaVariant::query()->whereHas('asset')->whereKey($variant->getKey())->exists())->toBeFalse();

    // Under the owning tenant the variant is reachable through its asset again.
    asTenant(2);
    expect(MediaVariant::query()->whereHas('asset')->whereKey($variant->getKey())->exists())->toBeTrue();
});

// ---------------------------------------------------------------------------------------------
// Folders follow the asset: a folder can never link (move) an asset across a tenant boundary.
// ---------------------------------------------------------------------------------------------

it('refuses to move an asset into a folder that already holds another organization\'s asset', function (): void {
    $service = app(MediaFolderService::class);

    $folder = MediaFolder::factory()->create();
    $org1Asset = MediaAsset::factory()->ready()->organization(1)->create();
    $org2Asset = MediaAsset::factory()->ready()->organization(2)->create();

    // Seed the folder with an org1 asset.
    $service->assignAsset($org1Asset, $folder, actorId: 1);

    // Moving an org2 asset into the same folder is a cross-tenant link — rejected.
    expect(fn () => $service->assignAsset($org2Asset, $folder, actorId: 1))
        ->toThrow(\App\Platform\Media\Exceptions\MediaValidationException::class);

    // The org2 asset stays at root (folder_id NULL) — nothing was linked across tenants.
    expect($org2Asset->fresh()->folder_id)->toBeNull();
});

it('refuses to mix a GLOBAL asset into a folder that holds an org-private asset', function (): void {
    $service = app(MediaFolderService::class);

    $folder = MediaFolder::factory()->create();
    $orgAsset = MediaAsset::factory()->ready()->organization(1)->create();
    $globalAsset = MediaAsset::factory()->ready()->create(); // organization_id NULL

    $service->assignAsset($orgAsset, $folder, actorId: 1);

    expect(fn () => $service->assignAsset($globalAsset, $folder, actorId: 1))
        ->toThrow(\App\Platform\Media\Exceptions\MediaValidationException::class);
});

it('allows moving same-tenant assets into one folder', function (): void {
    $service = app(MediaFolderService::class);

    $folder = MediaFolder::factory()->create();
    $a = MediaAsset::factory()->ready()->organization(1)->create();
    $b = MediaAsset::factory()->ready()->organization(1)->create();

    $service->assignAsset($a, $folder, actorId: 1);
    $service->assignAsset($b, $folder, actorId: 1);

    expect($a->fresh()->folder_id)->toBe($folder->getKey())
        ->and($b->fresh()->folder_id)->toBe($folder->getKey());
});
