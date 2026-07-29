<?php

namespace App\Platform\Media\Services;

use App\Platform\Media\Events\MediaDeleted;
use App\Platform\Media\Events\MediaDetached;
use App\Platform\Media\Exceptions\MediaInUseException;
use App\Platform\Media\Ingestion\IngestionProviderManager;
use App\Platform\Media\Models\MediaAsset;
use App\Platform\Media\Models\MediaAttachment;
use App\Platform\Shared\Audit\AuditLogger;
use App\Platform\Shared\Media\Enums\MediaStatus;
use Illuminate\Support\Facades\DB;

/**
 * P2/W04 - Deletes an asset: removes the remote asset, soft-deletes the row, and marks it Deleted.
 * Refuses while the asset is still attached somewhere unless the caller forces a cascading detach.
 * The remote delete is idempotent (adapters swallow "already gone").
 */
class MediaDeletionService
{
    public function __construct(
        private readonly IngestionProviderManager $providers,
        private readonly AuditLogger $audit,
    ) {}

    public function deleteAsset(MediaAsset $asset, int $actorId, bool $force = false): void
    {
        $usage = MediaAttachment::query()->where('media_asset_id', $asset->id)->count();

        if ($usage > 0 && ! $force) {
            throw new MediaInUseException(
                'This media is in use and cannot be deleted.',
                ['usage_count' => $usage],
            );
        }

        // Remote deletion runs outside the DB transaction: it is a best-effort, idempotent side
        // effect and must not hold a row lock while waiting on a vendor.
        if ($asset->provider_ref !== null) {
            $this->providers->for($asset->provider)->deleteRemote((string) $asset->provider_ref);
        }

        DB::transaction(function () use ($asset, $actorId, $force): void {
            /** @var MediaAsset $locked */
            $locked = MediaAsset::query()->whereKey($asset->getKey())->lockForUpdate()->firstOrFail();

            if ($force) {
                $attachments = MediaAttachment::query()->where('media_asset_id', $locked->id)->get();
                foreach ($attachments as $attachment) {
                    MediaDetached::dispatch(
                        (string) $locked->public_id,
                        $attachment->attachable_type,
                        $attachment->attachable_id,
                        $actorId,
                    );
                }
                MediaAttachment::query()->where('media_asset_id', $locked->id)->delete();
            }

            if ($locked->status->canTransitionTo(MediaStatus::Deleted)) {
                $locked->forceFill(['status' => MediaStatus::Deleted->value])->save();
            }

            $locked->delete(); // soft delete
        });

        MediaDeleted::dispatch((string) $asset->public_id, $actorId);
        $this->audit->log('media.deleted', $asset, ['forced' => $force], $actorId);
    }
}
