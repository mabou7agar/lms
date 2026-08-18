<?php

namespace App\Platform\Identity\Policies;

use App\Platform\Identity\Models\User;
use App\Platform\Shared\Policies\BasePolicy;
use Spatie\Permission\Models\Role;

/**
 * Authorization for role management (Shield's Role resource), gated by the identity.roles.*
 * permissions. The four protected system roles (super_admin, admin, instructor, student) are
 * load-bearing: they may not be edited or deleted by ordinary role managers — only super_admin
 * may edit them (via the central gate bypass), and none may be deleted through the panel.
 * Deletion of a system role is additionally blocked at the model layer (IdentityServiceProvider)
 * so the super_admin gate bypass can never remove the platform's own access spine.
 */
class RolePolicy extends BasePolicy
{
    /** @var list<string> */
    private const PROTECTED_SYSTEM_ROLES = ['super_admin', 'admin', 'instructor', 'student'];

    public function before(mixed $user, string $ability): ?bool
    {
        if ($user instanceof User && $user->hasRole('super_admin')) {
            return true;
        }

        return null;
    }

    public function viewAny(User $user): bool
    {
        return $user->can('identity.roles.view');
    }

    public function view(User $user, Role $role): bool
    {
        return $user->can('identity.roles.view');
    }

    public function create(User $user): bool
    {
        return $user->can('identity.roles.manage');
    }

    public function update(User $user, Role $role): bool
    {
        if ($this->isProtectedSystemRole($role)) {
            return false;
        }

        return $user->can('identity.roles.manage');
    }

    public function delete(User $user, Role $role): bool
    {
        if ($this->isProtectedSystemRole($role)) {
            return false;
        }

        return $user->can('identity.roles.manage');
    }

    private function isProtectedSystemRole(Role $role): bool
    {
        return in_array($role->name, self::PROTECTED_SYSTEM_ROLES, true);
    }
}
