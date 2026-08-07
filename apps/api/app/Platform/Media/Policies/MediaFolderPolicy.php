<?php

namespace App\Platform\Media\Policies;

use App\Platform\Identity\Contracts\Actor;
use App\Platform\Media\Models\MediaFolder;
use App\Platform\Shared\Policies\BasePolicy;

/**
 * Phase 8 / D1 - Authorizes DAM folder management. Folders are an admin organizational tool, so the
 * baseline is the same admin/super_admin operator gate the DAM itself uses, plus per-record ownership
 * (the folder's creator) for mutations. super_admin bypasses via before(). No tenancy logic (T1 out
 * of scope) — the reserved owner_id column is not consulted here.
 */
class MediaFolderPolicy extends BasePolicy
{
    public function before(mixed $user, string $ability): ?bool
    {
        if ($user instanceof Actor && $user->hasRole('super_admin')) {
            return true;
        }

        return null;
    }

    public function viewAny(Actor $user): bool
    {
        return $user->hasRole(['admin', 'super_admin']);
    }

    public function view(Actor $user, MediaFolder $folder): bool
    {
        return $this->manages($user, $folder);
    }

    public function create(Actor $user): bool
    {
        return $user->hasRole(['admin', 'super_admin']);
    }

    public function update(Actor $user, MediaFolder $folder): bool
    {
        return $this->manages($user, $folder);
    }

    public function delete(Actor $user, MediaFolder $folder): bool
    {
        return $this->manages($user, $folder);
    }

    private function manages(Actor $user, MediaFolder $folder): bool
    {
        return $user->hasRole(['admin', 'super_admin']) && $folder->created_by === $user->actorId();
    }
}
