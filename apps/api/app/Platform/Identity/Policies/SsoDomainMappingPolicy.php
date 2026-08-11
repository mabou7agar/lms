<?php

namespace App\Platform\Identity\Policies;

use App\Platform\Identity\Enums\Permission;
use App\Platform\Identity\Models\SsoDomainMapping;
use App\Platform\Identity\Models\User;
use App\Platform\Shared\Policies\BasePolicy;

/**
 * Tenant-isolated authorization for SSO domain mappings. An org-admin (holds ManageUsers) may manage
 * ONLY the mappings of their OWN organization — org A's admin can never see or modify org B's rows.
 * super_admin bypasses tenant scoping; verification is super_admin-only (a deliberate stub).
 */
class SsoDomainMappingPolicy extends BasePolicy
{
    public function before(mixed $user, string $ability): ?bool
    {
        if ($user instanceof User && $user->hasRole('super_admin')) {
            return true;
        }

        return null;
    }

    /** Manage (view/create) the caller's own-org mappings. */
    public function manage(User $user): bool
    {
        return $user->hasPermission(Permission::ManageUsers->value)
            && $user->organizationId() !== null;
    }

    public function update(User $user, SsoDomainMapping $mapping): bool
    {
        return $this->owns($user, $mapping);
    }

    public function delete(User $user, SsoDomainMapping $mapping): bool
    {
        return $this->owns($user, $mapping);
    }

    /** Toggling the verified stub is super_admin-only — handled by before(); everyone else is denied. */
    public function verify(User $user, SsoDomainMapping $mapping): bool
    {
        return false;
    }

    private function owns(User $user, SsoDomainMapping $mapping): bool
    {
        return $user->hasPermission(Permission::ManageUsers->value)
            && $user->organizationId() !== null
            && $mapping->organization_id === $user->organizationId();
    }
}
