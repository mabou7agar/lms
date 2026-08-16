<?php

namespace App\Platform\Media\Adapters;

use App\Platform\Media\Models\MediaAsset;
use App\Platform\Media\Ports\MediaAssetRefResolver;
use App\Platform\Shared\Media\Contracts\MediaAssetLookupPort;
use App\Platform\Shared\Media\Data\MediaAssetRef;

/**
 * The Media platform's implementation of MediaAssetLookupPort. Thin: it finds the asset and hands it
 * to the same MediaAssetRefResolver the media admin surface uses, so there is one definition of how
 * an asset becomes a signable reference rather than a second one drifting alongside it.
 */
final class MediaAssetLookupAdapter implements MediaAssetLookupPort
{
    public function __construct(private readonly MediaAssetRefResolver $refs) {}

    public function refForAssetId(int $assetId): ?MediaAssetRef
    {
        $asset = MediaAsset::query()->find($assetId);

        return $asset instanceof MediaAsset ? $this->refs->refForAsset($asset) : null;
    }

    public function assetIdByPublicId(string $publicId): ?int
    {
        $id = MediaAsset::query()->where('public_id', $publicId)->value('id');

        return $id === null ? null : (int) $id;
    }
}
