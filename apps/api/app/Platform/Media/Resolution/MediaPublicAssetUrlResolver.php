<?php

namespace App\Platform\Media\Resolution;

use App\Platform\Media\Enums\MediaVisibility;
use App\Platform\Media\Models\MediaAsset;
use App\Platform\Media\Ports\MediaAssetRefResolver;
use App\Platform\Shared\Helpers\Uuid;
use App\Platform\Shared\Media\Contracts\PlaybackPort;
use App\Platform\Shared\Media\Contracts\PublicAssetUrlResolver;
use App\Platform\Shared\Media\Exceptions\MediaUnavailableException;
use App\Platform\Shared\Tenancy\TenantContext;

/**
 * P1 - Media's implementation of the Shared PublicAssetUrlResolver seam. It maps a stored MediaPicker
 * value to a client-facing URL using the resolved asset's VISIBILITY, and nothing else:
 *
 *   empty / null                 -> null
 *   legacy (not a UUID)          -> passthrough, UNCHANGED (a pre-existing URL/path keeps rendering)
 *   reference (a public_id UUID) -> resolve the MediaAsset, then by visibility:
 *       PUBLIC        -> a stable, fingerprinted public URL (PublicMediaUrlBuilder) — no expiry token
 *       AUTHENTICATED -> a short-lived SIGNED URL (PlaybackPort) — reuses the existing signer
 *       PRIVATE       -> null (never exposed in a public context)
 *   missing / soft-deleted asset -> null (safe: no broken or secret-leaking URL)
 *
 * The reference/legacy split mirrors the Shared MediaPicker::classifyValue contract EXACTLY (a valid
 * UUID public_id is a reference; anything else non-empty is legacy), so the two components never
 * disagree about what a stored value is. No storage key or provider ref is ever emitted: the PUBLIC
 * URL is keyed by public id, and signed URLs come from the opaque PlaybackToken.
 *
 * A PUBLIC/AUTHENTICATED asset that is not yet Ready resolves to null (nothing to serve yet) rather
 * than a URL that would 404.
 *
 * TENANCY (T1 Option-N, SHARED-OR-OWNED): the reference lookup rides MediaAsset's SharedOrOwnedTenant
 * Scope, so under a resolved tenant a public_id owned by ANOTHER organization simply does not resolve
 * (the query returns null -> null URL). On top of that automatic filter this resolver adds two safe
 * defaults enforced HERE, because a public renderer may run with NO tenant resolved (anonymous):
 *   - visibleToActiveTenant(): a NON-global (org-owned) asset resolves ONLY for its owning tenant.
 *     An anonymous caller or a different tenant gets null even for a PUBLIC org asset — an org asset is
 *     never treated as internet-public.
 *   - org-scoped PUBLIC is tenant-bound: only GLOBAL (organization_id NULL) PUBLIC assets emit the
 *     stable, long-cacheable public URL. An org-owned PUBLIC asset is downgraded to a short-lived
 *     SIGNED URL (like AUTHENTICATED) so it can never be addressed via an unauthenticated CDN URL.
 */
class MediaPublicAssetUrlResolver implements PublicAssetUrlResolver
{
    public function __construct(
        private readonly PlaybackPort $playback,
        private readonly MediaAssetRefResolver $refs,
        private readonly PublicMediaUrlBuilder $publicUrls,
        private readonly TenantContext $tenant,
    ) {}

    public function resolve(?string $storedValue): ?string
    {
        if ($storedValue === null || trim($storedValue) === '') {
            return null;
        }

        // Mirror the Shared MediaPicker::classifyValue contract EXACTLY: a valid UUID is a reference,
        // anything else non-empty is a legacy URL/path that renders verbatim — never a raw storage key,
        // because a legacy value is a caller-supplied URL/path, not an internal identifier.
        if (! Uuid::isValid($storedValue)) {
            return $storedValue;
        }

        $asset = MediaAsset::query()->where('public_id', $storedValue)->first();

        // Missing OR soft-deleted (SoftDeletes excludes trashed) OR filtered out cross-tenant by the
        // SharedOrOwnedTenantScope -> null, safely.
        if ($asset === null) {
            return null;
        }

        // Safe default: an org-owned asset is tenant-bound. It resolves ONLY for its owning tenant; an
        // anonymous caller / a different tenant never gets a URL for it (even PUBLIC). Global assets
        // (organization_id NULL) resolve for everyone, exactly as before.
        if (! $this->visibleToActiveTenant($asset)) {
            return null;
        }

        return match ($asset->visibility) {
            // Only GLOBAL PUBLIC assets get the stable, unauthenticated CDN URL. An org-owned PUBLIC
            // asset is downgraded to a short-lived signed URL so it is never internet-addressable.
            MediaVisibility::Public => $asset->isGlobal()
                ? $this->publicUrl($asset)
                : $this->signedUrl($asset),
            MediaVisibility::Authenticated => $this->signedUrl($asset),
            MediaVisibility::Private => null,
        };
    }

    /**
     * A global asset (no owning org) is visible to everyone, incl. anonymous. A non-global (org-owned)
     * asset is visible ONLY when a tenant is resolved AND it owns the asset — never cross-tenant.
     */
    private function visibleToActiveTenant(MediaAsset $asset): bool
    {
        if ($asset->isGlobal()) {
            return true;
        }

        $tenantId = $this->tenant->id();

        return $tenantId !== null && $asset->belongsToTenant($tenantId);
    }

    public function resolveMany(array $storedValues): array
    {
        return array_map(fn (?string $value): ?string => $this->resolve($value), $storedValues);
    }

    /** Stable public URL, only once the asset is actually servable (Ready). */
    private function publicUrl(MediaAsset $asset): ?string
    {
        if (! $asset->status->isPlayable()) {
            return null;
        }

        return $this->publicUrls->urlFor($asset);
    }

    /** Short-lived signed URL via the existing PlaybackPort; null if it cannot be signed. */
    private function signedUrl(MediaAsset $asset): ?string
    {
        if (! $asset->status->isPlayable()) {
            return null;
        }

        try {
            $token = $this->playback->issue(
                $this->refs->refForAsset($asset),
                (int) config('media.playback.ttl_seconds', 600),
            );
        } catch (MediaUnavailableException) {
            return null;
        }

        return $token->url;
    }
}
