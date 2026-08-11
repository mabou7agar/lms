<?php

namespace App\Platform\Branding\Policies;

use App\Platform\Branding\Models\CustomDomain;
use App\Platform\Identity\Contracts\Actor;
use App\Platform\Shared\Policies\BasePolicy;

/**
 * Tenant-isolated authorization for custom domains. An org-admin (users-manage permission + org
 * membership) may list/add their OWN org's domains and delete only rows their org owns — org A can
 * never see or modify org B's domains. Verification is super_admin-only (a deliberate stub): the
 * super_admin bypass in before() grants it, everyone else is denied by verify().
 *
 * Depends on the Identity CONTRACT (Actor) only, never the User model.
 */
class CustomDomainPolicy extends BasePolicy
{
    /** The org-admin permission string (== Identity Permission::ManageUsers). */
    private const MANAGE_USERS = 'identity.users.manage';

    public function before(mixed $user, string $ability): ?bool
    {
        if ($user instanceof Actor && $user->hasRole('super_admin')) {
            return true;
        }

        return null;
    }

    /** List/add the caller's own-org domains. */
    public function manage(Actor $user): bool
    {
        return $user->hasPermission(self::MANAGE_USERS)
            && $user->organizationId() !== null;
    }

    public function delete(Actor $user, CustomDomain $domain): bool
    {
        return $this->owns($user, $domain);
    }

    /** Toggling the verified stub is super_admin-only — granted by before(); everyone else is denied. */
    public function verify(Actor $user, CustomDomain $domain): bool
    {
        return false;
    }

    private function owns(Actor $user, CustomDomain $domain): bool
    {
        return $user->hasPermission(self::MANAGE_USERS)
            && $user->organizationId() !== null
            && $domain->organization_id === $user->organizationId();
    }
}
