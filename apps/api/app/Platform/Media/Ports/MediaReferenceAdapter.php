<?php

namespace App\Platform\Media\Ports;

use App\Platform\Media\Exceptions\MediaAccessDeniedException;
use App\Platform\Media\Exceptions\MediaNotReadyException;
use App\Platform\Media\Models\MediaAsset;
use App\Platform\Shared\Media\Contracts\MediaReferencePort;
use App\Platform\Shared\Media\Data\MediaReference;

/**
 * P2/W04 - Media's implementation of the cross-context MediaReferencePort. This is the ONLY safe
 * seam Authoring/Assessment use to reference a Media asset by its PUBLIC id: it returns a
 * client-safe MediaReference (never a storage key / provider ref) and enforces ownership + readiness
 * before an asset may be USED (attached to a block, accepted as a submission). Callers never import
 * the MediaAsset model.
 */
class MediaReferenceAdapter implements MediaReferencePort
{
    public function reference(string $mediaPublicId): ?MediaReference
    {
        $asset = $this->find($mediaPublicId);

        if ($asset === null) {
            return null;
        }

        return new MediaReference(
            publicId: (string) $asset->public_id,
            type: $asset->type,
            status: $asset->status,
            ownerActorId: $asset->created_by,
            originalFilename: $asset->original_filename,
            sizeBytes: $asset->size_bytes,
            durationSeconds: $asset->duration_seconds,
        );
    }

    public function assertUsableBy(string $mediaPublicId, int $actorId): void
    {
        $asset = $this->find($mediaPublicId);

        // Missing OR not-owned are deliberately indistinguishable (no existence leak).
        if ($asset === null || $asset->created_by !== $actorId) {
            throw new MediaAccessDeniedException;
        }

        if (! $asset->status->isPlayable()) {
            throw new MediaNotReadyException('This media is not ready to be used yet.');
        }
    }

    private function find(string $mediaPublicId): ?MediaAsset
    {
        return MediaAsset::query()->where('public_id', $mediaPublicId)->first();
    }
}
