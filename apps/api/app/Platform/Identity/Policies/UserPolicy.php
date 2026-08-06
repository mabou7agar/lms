<?php

namespace App\Platform\Identity\Policies;

use App\Platform\Identity\Models\User;
use App\Platform\Shared\Policies\BasePolicy;

/**
 * Authorization for user records. A user may always act on their own account; broader
 * management is gated by the identity permissions (used by the admin panel later).
 */
class UserPolicy extends BasePolicy
{
    public function before(mixed $user, string $ability): ?bool
    {
        if ($user instanceof User && $user->hasRole('super_admin')) {
            return true;
        }

        return null;
    }

    public function view(User $user, User $target): bool
    {
        return $user->is($target) || $user->can('identity.users.view');
    }

    public function update(User $user, User $target): bool
    {
        return $user->is($target) || $user->can('identity.users.manage');
    }

    /**
     * Whether $user may impersonate $target. Gates the panel action; the ImpersonationManager
     * re-checks the permission and enforces the self/super_admin/nested guards below the gate,
     * so this only needs to keep the button off self- and super_admin rows.
     */
    public function impersonate(User $user, User $target): bool
    {
        if ($user->is($target) || $target->hasRole('super_admin')) {
            return false;
        }

        return $user->can('identity.users.impersonate');
    }
}
