<?php

namespace App\Domains\Crm\Services;

use App\Domains\Crm\Enums\MemberStatus;
use App\Domains\Crm\Models\Department;
use App\Domains\Crm\Models\OrganizationMember;
use App\Domains\Crm\Models\Team;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Turns "assign this to the sales department" into the actual list of employees.
 *
 * Every query starts from the organization id the caller's manager scope resolved, so a target that
 * belongs to another organization is not found rather than refused — a manager cannot discover
 * another company's departments by probing public ids. Only ACTIVE members are ever returned: a
 * removed or deactivated employee is not somebody you can hand training to.
 *
 * Extracted from CourseAssignmentController so the seat-assignment portal resolves targets by exactly
 * the same rules as the free course grant, rather than growing a second, subtly different copy.
 */
class OrganizationTargetResolver
{
    /** The target kinds the portal accepts, in the order the UI presents them. */
    public const TYPES = ['organization', 'member', 'department', 'team'];

    /**
     * @return EloquentCollection<int, OrganizationMember>
     */
    public function resolve(string $targetType, ?string $targetId, int $organizationId): EloquentCollection
    {
        $base = OrganizationMember::query()
            ->where('organization_id', $organizationId)
            ->where('status', MemberStatus::Active->value);

        return match ($targetType) {
            'organization' => $base->get(),
            'member' => $this->singleMember($base, $this->requiredTargetId($targetId)),
            'department' => $this->departmentMembers($base, $this->requiredTargetId($targetId), $organizationId),
            'team' => $this->teamMembers($base, $this->requiredTargetId($targetId), $organizationId),
            default => new EloquentCollection,
        };
    }

    /**
     * @param  Builder<OrganizationMember>  $base
     * @return EloquentCollection<int, OrganizationMember>
     */
    private function singleMember(Builder $base, string $memberId): EloquentCollection
    {
        $members = $base->where('public_id', $memberId)->get();

        if ($members->isEmpty()) {
            throw new NotFoundHttpException('Member not found.');
        }

        return $members;
    }

    /**
     * @param  Builder<OrganizationMember>  $base
     * @return EloquentCollection<int, OrganizationMember>
     */
    private function departmentMembers(Builder $base, string $departmentId, int $organizationId): EloquentCollection
    {
        $department = Department::query()
            ->where('organization_id', $organizationId)
            ->where('public_id', $departmentId)
            ->first();

        if ($department === null) {
            throw new NotFoundHttpException('Department not found.');
        }

        return $base->where('department_id', $department->getKey())->get();
    }

    /**
     * @param  Builder<OrganizationMember>  $base
     * @return EloquentCollection<int, OrganizationMember>
     */
    private function teamMembers(Builder $base, string $teamId, int $organizationId): EloquentCollection
    {
        $team = Team::query()
            ->where('organization_id', $organizationId)
            ->where('public_id', $teamId)
            ->first();

        if ($team === null) {
            throw new NotFoundHttpException('Team not found.');
        }

        return $base
            ->whereHas('teams', fn (Builder $query) => $query->where('crm_teams.id', $team->getKey()))
            ->get();
    }

    private function requiredTargetId(?string $targetId): string
    {
        if ($targetId === null || $targetId === '') {
            throw new NotFoundHttpException('Target not found.');
        }

        return $targetId;
    }
}
