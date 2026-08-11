<?php

namespace App\Platform\Branding\Policies;

use App\Platform\Identity\Contracts\Actor;
use App\Platform\Shared\Policies\BasePolicy;

/**
 * Tenant-isolated authorization for an organization's brand override. An org-admin (holds the
 * users-manage permission and belongs to an organization) may read/update ONLY their OWN org's brand
 * — the self-scoped endpoints derive the org from the caller, so org A can never reach org B's brand.
 *
 * Depends on the Identity CONTRACT (Actor) only, never the User model: Laravel injects the
 * authenticated principal (User implements Actor) into these methods unchanged.
 */
class OrganizationBrandPolicy extends BasePolicy
{
    /** The org-admin permission string (== Identity Permission::ManageUsers), referenced by value to avoid importing the Identity enum. */
    private const MANAGE_USERS = 'identity.users.manage';

    /** Read/update the caller's OWN-org brand. Requires the users-manage permission + org membership. */
    public function manage(Actor $user): bool
    {
        return $user->hasPermission(self::MANAGE_USERS)
            && $user->organizationId() !== null;
    }
}
