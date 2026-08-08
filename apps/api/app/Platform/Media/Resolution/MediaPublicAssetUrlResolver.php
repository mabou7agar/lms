<?php

namespace App\Platform\Media\Resolution;

use App\Platform\Media\Enums\MediaVisibility;
use App\Platform\Media\Models\MediaAsset;
use App\Platform\Media\Ports\MediaAssetRefResolver;
use App\Platform\Shared\Helpers\Uuid;
use App\Platform\Shared\Media\Contracts\PlaybackPort;
use App\Platform\Shared\Media\Contracts\PublicAssetUrlResolver;
use App\Platform\Shared\Media\Exceptions\MediaUnavailableException;

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
 * TENANCY NOTE (T1, later phase): the reference lookup must be scoped to the acting tenant before an
 * asset resolves, so a public_id from another organization cannot be resolved here.
 */
class MediaPublicAssetUrlResolver implements PublicAssetUrlResolver
{
    public function __construct(
        private readonly PlaybackPort $playback,
        private readonly MediaAssetRefResolver $refs,
        private readonly PublicMediaUrlBuilder $publicUrls,
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

        // Missing OR soft-deleted (SoftDeletes excludes trashed) -> null, safely.
        if ($asset === null) {
            return null;
        }

        return match ($asset->visibility) {
            MediaVisibility::Public => $this->publicUrl($asset),
            MediaVisibility::Authenticated => $this->signedUrl($asset),
            MediaVisibility::Private => null,
        };
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
