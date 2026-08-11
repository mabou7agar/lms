<?php

namespace App\Domains\Crm\Http\Controllers\Api\V1\Enterprise;

use App\Domains\Crm\Http\Requests\Enterprise\AssignManagerRequest;
use App\Domains\Crm\Http\Requests\Enterprise\SaveTeamRequest;
use App\Domains\Crm\Http\Resources\TeamResource;
use App\Domains\Crm\Models\Department;
use App\Domains\Crm\Models\OrganizationMember;
use App\Domains\Crm\Models\Team;
use App\Platform\Shared\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Team CRUD + manager assignment for the enterprise portal. Same authority model as departments;
 * teams may optionally belong to a department (referenced by public id). Tenant-scoped throughout.
 */
class TeamController extends EnterpriseController
{
    public function index(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', Team::class);

        $scope = $this->scope($request);
        $perPage = max(1, min((int) $request->integer('per_page', 25), 100));

        $query = Team::query()->where('organization_id', $scope->organizationId);

        if (! $scope->viewAll) {
            $query->whereIn('id', $scope->teamIds ?: [0]);
        }

        $teams = $query->latest('id')->paginate($perPage)->withQueryString();

        return ApiResponse::paginated($teams, TeamResource::class);
    }

    public function store(SaveTeamRequest $request): JsonResponse
    {
        Gate::authorize('create', Team::class);

        $data = $request->validated();

        $team = Team::create([
            'name' => (string) $data['name'],
            'department_id' => $this->resolveDepartmentId(is_string($data['department_id'] ?? null) ? $data['department_id'] : null),
        ]);

        return ApiResponse::created(new TeamResource($team), 'Team created.');
    }

    public function update(SaveTeamRequest $request, Team $team): JsonResponse
    {
        Gate::authorize('update', $team);

        $data = $request->validated();

        $team->forceFill([
            'name' => (string) $data['name'],
            'department_id' => $this->resolveDepartmentId(is_string($data['department_id'] ?? null) ? $data['department_id'] : null),
        ])->save();

        return ApiResponse::updated(new TeamResource($team), 'Team updated.');
    }

    public function destroy(Request $request, Team $team): JsonResponse
    {
        Gate::authorize('delete', $team);

        $team->delete();

        return ApiResponse::deleted('Team deleted.');
    }

    public function assignManager(AssignManagerRequest $request, Team $team): JsonResponse
    {
        Gate::authorize('update', $team);

        $memberId = $request->validated()['member_id'] ?? null;
        $team->forceFill([
            'manager_id' => $this->resolveManagerUserId(is_string($memberId) ? $memberId : null, (int) $team->getAttribute('organization_id')),
        ])->save();

        return ApiResponse::updated(new TeamResource($team), 'Manager assigned.');
    }

    private function resolveDepartmentId(?string $departmentPublicId): ?int
    {
        if ($departmentPublicId === null || $departmentPublicId === '') {
            return null;
        }

        $department = Department::where('public_id', $departmentPublicId)->first();

        if ($department === null) {
            throw new NotFoundHttpException('Department not found.');
        }

        return (int) $department->getKey();
    }

    private function resolveManagerUserId(?string $memberPublicId, int $organizationId): ?int
    {
        if ($memberPublicId === null || $memberPublicId === '') {
            return null;
        }

        $member = OrganizationMember::where('public_id', $memberPublicId)
            ->where('organization_id', $organizationId)
            ->first();

        if ($member === null || $member->getAttribute('user_id') === null) {
            throw new NotFoundHttpException('Member (with a user account) not found.');
        }

        return (int) $member->getAttribute('user_id');
    }
}
