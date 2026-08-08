<?php

namespace App\Platform\Media\Services;

use App\Platform\Media\Exceptions\MediaValidationException;
use App\Platform\Media\Models\MediaAsset;
use App\Platform\Media\Models\MediaFolder;
use App\Platform\Shared\Audit\AuditLogger;
use App\Platform\Shared\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * Phase 8 / D1 - Folder lifecycle for the DAM: create, rename, move (reparent) and delete. The one
 * rule that matters for data safety: deleting a folder NEVER deletes its assets. By default every
 * asset in the folder is reassigned to root (folder_id = null) and every child folder is reparented
 * to the deleted folder's own parent, so nothing is orphaned and no binary is lost. All of this runs
 * in a single transaction. Move is cycle-guarded so a folder can never become its own ancestor.
 *
 * TENANCY (T1 Option-N): folders themselves carry NO tenant column — the matrix models them as
 * following their asset transitively, so they stay simple/unscoped. Tenant isolation is enforced at
 * the ASSET boundary instead: assignAsset() refuses to place an asset into a folder that already holds
 * an asset of a DIFFERENT organization, so a folder can never link (mix) assets across tenants and a
 * folder move can never drag an asset across a tenant boundary.
 */
class MediaFolderService
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly TenantContext $tenant,
    ) {}

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

    /**
     * Move a single asset into a folder, or to root when $folder is null. Moving to a folder is
     * rejected when that folder already holds an asset owned by a DIFFERENT organization, so an asset
     * can never be linked into another tenant's folder (nor a folder mix assets across tenants).
     *
     * @throws MediaValidationException on a cross-tenant folder target.
     */
    public function assignAsset(MediaAsset $asset, ?MediaFolder $folder, int $actorId): void
    {
        if ($folder !== null) {
            $this->assertTenantCompatible($asset, $folder);
        }

        MediaAsset::query()->whereKey($asset->getKey())->update(['folder_id' => $folder?->getKey()]);
        $this->audit->log('media.folder.asset_assigned', $asset, [
            'folder_id' => $folder?->getKey(),
        ], $actorId);
    }

    /**
     * Reject placing $asset into $folder when the folder already contains an asset owned by a different
     * organization. The folder's existing contents are read with tenancy BYPASSED so an invisible
     * cross-tenant occupant is still detected (otherwise the SharedOrOwnedTenantScope would hide it and
     * the mix would slip through). NULL (global) only matches NULL (global).
     *
     * @throws MediaValidationException when the target folder belongs to another organization.
     */
    private function assertTenantCompatible(MediaAsset $asset, MediaFolder $folder): void
    {
        $assetOrg = $asset->organization_id === null ? null : (int) $asset->organization_id;

        $existingOrgs = $this->tenant->runWithoutTenancy(
            static fn () => MediaAsset::query()
                ->where('folder_id', $folder->getKey())
                ->whereKeyNot($asset->getKey())
                ->distinct()
                ->pluck('organization_id')
        );

        foreach ($existingOrgs as $org) {
            $org = $org === null ? null : (int) $org;

            if ($org !== $assetOrg) {
                throw new MediaValidationException(
                    'An asset cannot be moved into a folder that belongs to a different organization.',
                    ['field' => 'folder_id'],
                );
            }
        }
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
