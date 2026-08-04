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
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * P2/W04 - Deletes an asset: soft-deletes the row and marks it Deleted first, then removes the
 * remote asset. Refuses while the asset is still attached somewhere unless the caller forces a
 * cascading detach.
 *
 * Ordering is deliberate. The usage re-check, the (optional) cascade detach and the soft-delete all
 * run INSIDE one transaction under a row lock, serialising with MediaAttachmentService::attach()
 * which locks the same asset row: a stale pre-count can never let an in-use asset be deleted, and no
 * attach committing in the window can leave a committed attachment pointing at a soft-deleted asset.
 * The remote delete runs only AFTER that transaction commits, so a rolled-back delete never leaves
 * the asset Ready with a dead provider_ref; it is idempotent (adapters swallow "already gone") and
 * best-effort (a vendor failure is logged and never resurrects the row).
 */
class MediaDeletionService
{
    public function __construct(
        private readonly IngestionProviderManager $providers,
        private readonly AuditLogger $audit,
    ) {}

    public function deleteAsset(MediaAsset $asset, int $actorId, bool $force = false): void
    {
        $provider = $asset->provider;

        // The transaction owns the row lock, the re-counted usage check and the soft-delete. It
        // yields the provider ref to purge remotely — but only once it has durably committed.
        $providerRef = DB::transaction(function () use ($asset, $actorId, $force): ?string {
            /** @var MediaAsset $locked */
            $locked = MediaAsset::query()->whereKey($asset->getKey())->lockForUpdate()->firstOrFail();

            // Re-check usage UNDER the lock. attach() takes this same row lock before inserting, so a
            // concurrent attach either already committed (and is counted here) or is still blocked
            // behind this lock — the MediaInUseException fires even when the pre-count was stale.
            $usage = MediaAttachment::query()->where('media_asset_id', $locked->id)->count();

            if ($usage > 0 && ! $force) {
                throw new MediaInUseException(
                    'This media is in use and cannot be deleted.',
                    ['usage_count' => $usage],
                );
            }

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

            return $locked->provider_ref !== null ? (string) $locked->provider_ref : null;
        });

        // Remote deletion runs only AFTER the transaction committed the row as Deleted. It is
        // best-effort + idempotent; a vendor failure is logged and must NOT resurrect the row to
        // Ready — the asset stays Deleted. If the transaction above rolled back we never reach here
        // and no remote object is touched.
        if ($providerRef !== null) {
            try {
                $this->providers->for($provider)->deleteRemote($providerRef);
            } catch (Throwable $e) {
                Log::warning('media.remote_delete_failed', [
                    'media_id' => (string) $asset->public_id,
                    'provider' => $provider->value,
                    'provider_ref' => $providerRef,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        MediaDeleted::dispatch((string) $asset->public_id, $actorId);
        $this->audit->log('media.deleted', $asset, ['forced' => $force], $actorId);
    }
}
