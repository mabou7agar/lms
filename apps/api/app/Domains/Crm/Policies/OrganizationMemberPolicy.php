<?php

namespace App\Domains\Crm\Policies;

use App\Domains\Crm\Models\OrganizationMember;
use App\Domains\Crm\Services\ManagerScope;
use App\Domains\Crm\Services\ManagerScopeResult;
use App\Platform\Identity\Contracts\Actor;
use App\Platform\Shared\Policies\BasePolicy;

/**
 * Enterprise-portal authorization for members, reporting, and seat/import surfaces. Every decision is
 * derived from the caller's ManagerScope in their CURRENT tenant organization, so a plain member (no
 * scope) is denied, a department/team manager only reaches members inside their scope, and org
 * mutations (seat/member/import) require owner-or-admin authority. Isolation is inherited from
 * ManagerScope: authority is always resolved against the resolved tenant, never client input.
 */
class OrganizationMemberPolicy extends BasePolicy
{
    private function scope(Actor $user): ManagerScopeResult
    {
        return app(ManagerScope::class)->forActor($user);
    }

    /** Any manager (owner/admin or a department/team manager) may read the manager report. */
    public function viewReports(Actor $user): bool
    {
        return $this->scope($user)->isManager();
    }

    /** Any manager may list members within their scope. */
    public function viewAny(Actor $user): bool
    {
        return $this->scope($user)->isManager();
    }

    /** Seat management (assign/release/resize) is an owner/admin capability. */
    public function manageSeats(Actor $user): bool
    {
        return $this->scope($user)->viewAll;
    }

    /** Bulk employee import + member mutations are owner/admin capabilities. */
    public function manageMembers(Actor $user): bool
    {
        return $this->scope($user)->viewAll;
    }

    /** A member is visible only when the caller's scope covers it. */
    public function view(Actor $user, OrganizationMember $member): bool
    {
        return $this->scope($user)->coversMember((int) $member->getKey());
    }

    /** Mutating a member (remove/role/deactivate) requires owner/admin authority. */
    public function manage(Actor $user, OrganizationMember $member): bool
    {
        $scope = $this->scope($user);

        return $scope->viewAll && (int) $member->getAttribute('organization_id') === $scope->organizationId;
    }
}
