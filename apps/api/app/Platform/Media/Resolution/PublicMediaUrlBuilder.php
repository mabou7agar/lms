<?php

namespace App\Platform\Media\Resolution;

use App\Platform\Media\Models\MediaAsset;

/**
 * P1 - Builds the STABLE, cacheable public URL for a PUBLIC-visibility asset. This is the "storage/
 * provider abstraction" for public delivery: it produces a canonical, versioned URL keyed by the
 * asset's PUBLIC id (never its storage key / provider ref), so the output is safe for long CDN
 * caching AND leaks no internal identifier.
 *
 * The URL carries a fingerprint (?v=...) derived from a one-way hash of the asset's CONTENT/version
 * token — its storage identifiers, size and public id. It is NOT a hash the client can invert back
 * to a key. Because a "replace" mints a NEW asset (new public id + new storage identifiers), the
 * repointed reference resolves to a NEW fingerprint automatically: the CDN cache is busted with no
 * short-lived token involved.
 *
 * Base/prefix come from config so an environment can point public delivery at a CDN host; they never
 * contain a per-asset secret.
 *
 * TENANCY NOTE (T1, later phase): the fingerprint and/or path must additionally bind the owning
 * organization so a URL minted for one tenant cannot be reused to address another tenant's asset.
 */
class PublicMediaUrlBuilder
{
    /** A stable, fingerprinted public URL for the given asset. Contains no storage key / provider ref. */
    public function urlFor(MediaAsset $asset): string
    {
        $base = rtrim((string) (config('media.public.base_url') ?: config('app.url')), '/');
        $prefix = trim((string) config('media.public.path_prefix', 'media/public'), '/');

        return sprintf('%s/%s/%s?v=%s', $base, $prefix, $asset->public_id, $this->fingerprint($asset));
    }

    /**
     * Short, one-way content/version fingerprint. Changes whenever the asset's stored content changes
     * (new storage key / provider ref / size) or when a reference is repointed to a replacement asset
     * (new public id). Never reveals any of those inputs.
     */
    public function fingerprint(MediaAsset $asset): string
    {
        $token = implode('|', [
            (string) $asset->storage_key,
            (string) $asset->provider_ref,
            (string) $asset->playback_id,
            (string) $asset->size_bytes,
            (string) $asset->public_id,
        ]);

        return substr(hash('sha256', $token), 0, 12);
    }
}
