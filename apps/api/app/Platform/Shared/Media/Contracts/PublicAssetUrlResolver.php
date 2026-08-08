<?php

namespace App\Platform\Shared\Media\Contracts;

/**
 * P1 - The single safe seam a PUBLIC renderer uses to turn a stored MediaPicker value into a
 * client-facing URL, WITHOUT importing any Media class. A stored value is one of three shapes (see
 * the Shared MediaPicker::classifyValue contract): a MediaAsset `public_id` reference, a legacy
 * URL/path string, or empty. The Media platform binds the concrete implementation.
 *
 * Resolution is driven by the resolved asset's VISIBILITY, never by the caller or the storage path:
 *   - PUBLIC        -> a STABLE, cacheable, fingerprinted/versioned public URL (NO expiry token) so a
 *                      CDN may cache it for a long time; the fingerprint derives from a content/version
 *                      token, so replacing the asset busts the URL.
 *   - AUTHENTICATED -> a short-lived SIGNED URL (issued through the existing PlaybackPort).
 *   - PRIVATE       -> null (a private asset is never exposed in a public context).
 *
 * A legacy URL/path (classifyValue === 'legacy') passes through UNCHANGED. Empty => null. A missing or
 * deleted asset => null. The implementation NEVER returns a raw storage key or provider identifier.
 *
 * TENANCY NOTE (T1, later phase): resolution must additionally be scoped to the acting tenant once
 * tenant scoping exists — both the PUBLIC fingerprinted URL and the AUTHENTICATED signed URL must
 * encode/verify the owning organization so one tenant can never resolve another tenant's asset.
 */
interface PublicAssetUrlResolver
{
    /** Resolve a single stored MediaPicker value to a client-facing URL, or null. */
    public function resolve(?string $storedValue): ?string;

    /**
     * Resolve many stored values at once, preserving keys. Convenience for renderers that emit a bag
     * of media fields (e.g. branding logos).
     *
     * @param  array<array-key, ?string>  $storedValues
     * @return array<array-key, ?string>
     */
    public function resolveMany(array $storedValues): array;
}
