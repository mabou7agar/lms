<?php

namespace App\Platform\Media\Ports;

use App\Platform\Media\Models\MediaAsset;
use App\Platform\Media\Models\MediaAttachment;
use App\Platform\Shared\Media\Data\MediaAccessPolicy;
use App\Platform\Shared\Media\Data\MediaAssetRef;
use App\Platform\Shared\Media\Enums\MediaProvider;

/**
 * P2/W04 - Attachment-aware resolution of a signable MediaAssetRef, ADDITIVE to Authoring's existing
 * MediaAssetPort (which resolves a lesson's LessonMedia). This does NOT rebind MediaAssetPort — it
 * would collide with Authoring's binding. Instead it resolves the "primary" media attached to any
 * scalar attachable (e.g. a lesson block) so a future PlaybackPort caller can sign a URL for
 * Media-owned assets. The integrator wires this where needed (see the deliverable notes).
 */
class MediaAssetRefResolver
{
    /** Resolve the primary ready asset attached to a given entity, or null. */
    public function assetForAttachable(string $attachableType, int $attachableId): ?MediaAssetRef
    {
        $attachment = MediaAttachment::query()
            ->where('attachable_type', $attachableType)
            ->where('attachable_id', $attachableId)
            ->orderByRaw("CASE WHEN role = 'primary' THEN 0 ELSE 1 END")
            ->orderBy('id')
            ->first();

        if ($attachment === null) {
            return null;
        }

        $asset = MediaAsset::query()->whereKey($attachment->media_asset_id)->first();

        if ($asset === null || ! $asset->status->isPlayable()) {
            return null;
        }

        return $this->refForAsset($asset);
    }

    /** Build a signable, storage-agnostic reference from a resolved MediaAsset (any status). */
    public function refForAsset(MediaAsset $asset): MediaAssetRef
    {
        return new MediaAssetRef(
            id: (string) $asset->public_id,
            provider: $asset->provider === MediaProvider::Mux ? 'mux' : 's3',
            playbackId: $asset->playback_id,
            storageKey: $asset->storage_key,
            mimeType: $asset->mime_type,
            durationSeconds: $asset->duration_seconds,
            policy: new MediaAccessPolicy(signed: true, visibility: 'private'),
            metadata: ['filesize' => $asset->size_bytes],
        );
    }
}
