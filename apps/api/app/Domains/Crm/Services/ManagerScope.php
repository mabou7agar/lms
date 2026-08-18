<?php

namespace App\Domains\Crm\Services;

use App\Domains\Crm\Enums\MemberRole;
use App\Domains\Crm\Enums\MemberStatus;
use App\Domains\Crm\Models\Department;
use App\Domains\Crm\Models\OrganizationMember;
use App\Domains\Crm\Models\Team;
use App\Platform\Identity\Contracts\Actor;
use App\Platform\Shared\Tenancy\Contracts\CurrentTenantProvider;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Resolves an authenticated user's ENTERPRISE authority inside their organization: org owner/admin →
 * the whole org; department manager → their department members; team manager → their team members. A
 * plain member has no authority.
 *
 * ISOLATION IS THE POINT: the organization is taken ONLY from the resolved tenant
 * (CurrentTenantProvider, itself derived from the authenticated user's organization_id — never client
 * input), and every membership query is confined to that organization (belt-and-braces alongside the
 * BelongsToTenant global scope). A department/team manager can therefore never resolve a scope that
 * reaches another department, team, or organization.
 */
class ManagerScope
{
    public function __construct(private readonly CurrentTenantProvider $tenant) {}

    /**
     * Resolve the scope for the given actor within the CURRENT tenant organization. When no org tenant
     * is resolved (a personal/no-org account), the scope is empty and {@see ManagerScopeResult::isManager()}
     * is false.
     */
    public function forActor(Actor $actor): ManagerScopeResult
    {
        $organizationId = $this->tenant->currentTenant()?->value;

        if ($organizationId === null) {
            return new ManagerScopeResult(0, null, false, [], [], [], []);
        }

        return $this->forUser($actor->actorId(), (int) $organizationId);
    }

    public function forUser(int $userId, int $organizationId): ManagerScopeResult
    {
        $membership = OrganizationMember::query()
            ->where('organization_id', $organizationId)
            ->where('user_id', $userId)
            ->where('status', MemberStatus::Active->value)
            ->first();

        $role = $membership?->role;
        $viewAll = in_array($role, [MemberRole::Owner, MemberRole::Admin], true);

        $departmentIds = $viewAll ? [] : Department::query()
            ->where('organization_id', $organizationId)
            ->where('manager_id', $userId)
            ->pluck('id')->map(static fn ($v): int => (int) $v)->all();

        $teamIds = $viewAll ? [] : Team::query()
            ->where('organization_id', $organizationId)
            ->where('manager_id', $userId)
            ->pluck('id')->map(static fn ($v): int => (int) $v)->all();

        [$memberIds, $userIds] = $viewAll
            ? $this->orgMemberSet($organizationId)
            : $this->managedMemberSet($organizationId, $departmentIds, $teamIds);

        return new ManagerScopeResult(
            organizationId: $organizationId,
            role: $role,
            viewAll: $viewAll,
            departmentIds: $departmentIds,
            teamIds: $teamIds,
            memberIds: $memberIds,
            userIds: $userIds,
        );
    }

    /**
     * Active-member user ids of a SPECIFIC department the caller has already been authorized for.
     *
     * @return list<int>
     */
    public function departmentUserIds(int $organizationId, int $departmentId): array
    {
        return $this->activeMembers($organizationId)
            ->where('department_id', $departmentId)
            ->whereNotNull('user_id')
            ->pluck('user_id')->map(static fn ($v): int => (int) $v)->unique()->values()->all();
    }

    /**
     * Active-member user ids of a SPECIFIC team the caller has already been authorized for.
     *
     * @return list<int>
     */
    public function teamUserIds(int $organizationId, int $teamId): array
    {
        $memberIds = DB::table('crm_team_members')->where('team_id', $teamId)->pluck('member_id');

        if ($memberIds->isEmpty()) {
            return [];
        }

        return $this->activeMembers($organizationId)
            ->whereIn('id', $memberIds)
            ->whereNotNull('user_id')
            ->pluck('user_id')->map(static fn ($v): int => (int) $v)->unique()->values()->all();
    }

    /**
     * @return array{0: list<int>, 1: list<int>}
     */
    private function orgMemberSet(int $organizationId): array
    {
        $rows = $this->activeMembers($organizationId)->get(['id', 'user_id']);

        return [
            $rows->pluck('id')->map(static fn ($v): int => (int) $v)->all(),
            $rows->pluck('user_id')->filter(static fn ($v): bool => $v !== null)->map(static fn ($v): int => (int) $v)->unique()->values()->all(),
        ];
    }

    /**
     * @param  list<int>  $departmentIds
     * @param  list<int>  $teamIds
     * @return array{0: list<int>, 1: list<int>}
     */
    private function managedMemberSet(int $organizationId, array $departmentIds, array $teamIds): array
    {
        $memberIds = [];

        if ($departmentIds !== []) {
            $memberIds = array_merge($memberIds, $this->activeMembers($organizationId)
                ->whereIn('department_id', $departmentIds)
                ->pluck('id')->map(static fn ($v): int => (int) $v)->all());
        }

        if ($teamIds !== []) {
            $pivotMemberIds = DB::table('crm_team_members')->whereIn('team_id', $teamIds)->pluck('member_id');

            if ($pivotMemberIds->isNotEmpty()) {
                $memberIds = array_merge($memberIds, $this->activeMembers($organizationId)
                    ->whereIn('id', $pivotMemberIds)
                    ->pluck('id')->map(static fn ($v): int => (int) $v)->all());
            }
        }

        $memberIds = array_values(array_unique($memberIds));

        $userIds = $memberIds === [] ? [] : OrganizationMember::query()
            ->whereIn('id', $memberIds)
            ->whereNotNull('user_id')
            ->pluck('user_id')->map(static fn ($v): int => (int) $v)->unique()->values()->all();

        return [$memberIds, $userIds];
    }

    /**
     * @return Builder<OrganizationMember>
     */
    private function activeMembers(int $organizationId): Builder
    {
        return OrganizationMember::query()
            ->where('organization_id', $organizationId)
            ->where('status', MemberStatus::Active->value);
    }
}
