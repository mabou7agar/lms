<?php

namespace App\Platform\Media\Services;

use App\Platform\Media\Exceptions\MediaValidationException;
use App\Platform\Media\Models\MediaAsset;
use App\Platform\Media\Models\MediaFolder;
use App\Platform\Shared\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;

/**
 * Phase 8 / D1 - Folder lifecycle for the DAM: create, rename, move (reparent) and delete. The one
 * rule that matters for data safety: deleting a folder NEVER deletes its assets. By default every
 * asset in the folder is reassigned to root (folder_id = null) and every child folder is reparented
 * to the deleted folder's own parent, so nothing is orphaned and no binary is lost. All of this runs
 * in a single transaction. Move is cycle-guarded so a folder can never become its own ancestor.
 */
class MediaFolderService
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function create(string $name, int $actorId, ?MediaFolder $parent = null, ?int $ownerId = null): MediaFolder
    {
        $folder = new MediaFolder;
        $folder->forceFill([
            'name' => $name,
            'parent_id' => $parent?->getKey(),
            'created_by' => $actorId,
            'owner_id' => $ownerId,
        ])->save();

        $this->audit->log('media.folder.created', $folder, ['name' => $name], $actorId);

        return $folder;
    }

    public function rename(MediaFolder $folder, string $name, int $actorId): MediaFolder
    {
        $folder->forceFill(['name' => $name])->save();
        $this->audit->log('media.folder.renamed', $folder, ['name' => $name], $actorId);

        return $folder;
    }

    /**
     * Reparent a folder. Passing null moves it to the root. Rejected when the target is the folder
     * itself or one of its descendants (which would create a cycle).
     *
     * @throws MediaValidationException on a self/descendant target.
     */
    public function move(MediaFolder $folder, ?MediaFolder $newParent, int $actorId): MediaFolder
    {
        if ($newParent !== null && $this->wouldCycle($folder, $newParent)) {
            throw new MediaValidationException(
                'A folder cannot be moved inside itself or one of its own subfolders.',
                ['field' => 'parent_id'],
            );
        }

        $folder->forceFill(['parent_id' => $newParent?->getKey()])->save();
        $this->audit->log('media.folder.moved', $folder, ['parent_id' => $newParent?->getKey()], $actorId);

        return $folder;
    }

    /**
     * Delete a folder WITHOUT deleting its assets. Assets are reassigned to root, child folders are
     * reparented to this folder's parent, then the (now empty) folder row is removed — all atomically.
     */
    public function delete(MediaFolder $folder, int $actorId): void
    {
        DB::transaction(function () use ($folder, $actorId): void {
            // Assets survive: they fall back to root (folder_id = null). query()->update bypasses the
            // asset's mass-assignment guard without touching any engine state.
            MediaAsset::query()->where('folder_id', $folder->getKey())->update(['folder_id' => null]);

            // Children survive too: reparent them to this folder's parent so the subtree is not orphaned.
            MediaFolder::query()
                ->where('parent_id', $folder->getKey())
                ->update(['parent_id' => $folder->parent_id]);

            $folder->delete();
        });

        $this->audit->log('media.folder.deleted', null, [
            'folder_public_id' => (string) $folder->public_id,
        ], $actorId);
    }

    /** Move a single asset into a folder, or to root when $folder is null. */
    public function assignAsset(MediaAsset $asset, ?MediaFolder $folder, int $actorId): void
    {
        MediaAsset::query()->whereKey($asset->getKey())->update(['folder_id' => $folder?->getKey()]);
        $this->audit->log('media.folder.asset_assigned', $asset, [
            'folder_id' => $folder?->getKey(),
        ], $actorId);
    }

    /** True when moving $folder under $target would make $folder its own ancestor. */
    private function wouldCycle(MediaFolder $folder, MediaFolder $target): bool
    {
        $cursor = $target;

        while ($cursor !== null) {
            if ($cursor->getKey() === $folder->getKey()) {
                return true;
            }

            $cursor = $cursor->parent_id !== null
                ? MediaFolder::query()->find($cursor->parent_id)
                : null;
        }

        return false;
    }
}
