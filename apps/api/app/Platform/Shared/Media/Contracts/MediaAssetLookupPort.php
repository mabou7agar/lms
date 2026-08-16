<?php

namespace App\Platform\Shared\Media\Contracts;

use App\Platform\Shared\Media\Data\MediaAssetRef;

/**
 * Resolve any library asset, by its own id, into a signable reference.
 *
 * Distinct from {@see MediaAssetPort}, which answers "what media does this LESSON have" and is
 * implemented by Authoring. This one is owned by the MEDIA platform, because the question is about
 * the library itself: a course resource is a publication decision pointing at an asset, and the
 * publishing context must be able to turn that pointer into something signable without importing a
 * Media model or ever seeing a raw storage key.
 *
 * Returns null for an unknown or deleted asset — a dangling publication is a missing file, not an
 * error worth crashing a page over.
 */
interface MediaAssetLookupPort
{
    public function refForAssetId(int $assetId): ?MediaAssetRef;

    /**
     * The internal id behind an asset's public id, or null when there is no such asset.
     *
     * A publishing context stores the internal key (so a foreign key can hold the reference) but
     * only ever RECEIVES the public one, because that is the only identifier the picker and the API
     * traffic in. This is the one translation, and it is deliberately not an existence oracle:
     * callers must still authorize the pick separately.
     */
    public function assetIdByPublicId(string $publicId): ?int;
}
