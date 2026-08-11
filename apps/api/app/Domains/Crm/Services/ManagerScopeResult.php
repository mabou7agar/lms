<?php

namespace App\Domains\Crm\Services;

use App\Domains\Crm\Enums\MemberRole;

/**
 * The resolved authority of one user inside one organization: whether they may see the whole org
 * (owner/admin), which departments/teams they manage, and the concrete member/user id set that
 * authority covers. Immutable — produced only by {@see ManagerScope}, never mutated by callers.
 *
 * A user is a "manager" iff they can see the whole org OR they manage at least one department or team.
 * A plain member (or a `manager`-role member assigned to nothing) is NOT a manager and its covered
 * sets are empty — the authorization floor the enterprise endpoints enforce.
 */
final readonly class ManagerScopeResult
{
    /**
     * @param  list<int>  $departmentIds  departments this user manages
     * @param  list<int>  $teamIds        teams this user manages
     * @param  list<int>  $memberIds      organization_members.id covered by this scope
     * @param  list<int>  $userIds        distinct users.id covered by this scope (members with an account)
     */
    public function __construct(
        public int $organizationId,
        public ?MemberRole $role,
        public bool $viewAll,
        public array $departmentIds,
        public array $teamIds,
        public array $memberIds,
        public array $userIds,
    ) {}

    public function isManager(): bool
    {
        return $this->viewAll || $this->departmentIds !== [] || $this->teamIds !== [];
    }

    public function coversDepartment(int $departmentId): bool
    {
        return $this->viewAll || in_array($departmentId, $this->departmentIds, true);
    }

    public function coversTeam(int $teamId): bool
    {
        return $this->viewAll || in_array($teamId, $this->teamIds, true);
    }

    public function coversMember(int $memberId): bool
    {
        return in_array($memberId, $this->memberIds, true);
    }
}
