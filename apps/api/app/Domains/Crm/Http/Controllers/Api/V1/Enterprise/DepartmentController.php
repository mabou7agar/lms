<?php

namespace App\Domains\Crm\Http\Controllers\Api\V1\Enterprise;

use App\Domains\Crm\Http\Requests\Enterprise\AssignManagerRequest;
use App\Domains\Crm\Http\Requests\Enterprise\SaveDepartmentRequest;
use App\Domains\Crm\Http\Resources\DepartmentResource;
use App\Domains\Crm\Models\Department;
use App\Domains\Crm\Models\OrganizationMember;
use App\Platform\Shared\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Department CRUD + manager assignment for the enterprise portal. Structural changes are owner/admin
 * only (DepartmentPolicy); a department manager may list/view the departments their scope covers. All
 * rows are tenant-scoped (BelongsToTenant), so cross-org access dead-ends at a 404.
 */
class DepartmentController extends EnterpriseController
{
    public function index(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', Department::class);

        $scope = $this->scope($request);
        $perPage = max(1, min((int) $request->integer('per_page', 25), 100));

        $query = Department::query()->where('organization_id', $scope->organizationId)->withCount('members');

        if (! $scope->viewAll) {
            $query->whereIn('id', $scope->departmentIds ?: [0]);
        }

        $departments = $query->latest('id')->paginate($perPage)->withQueryString();

        return ApiResponse::paginated($departments, DepartmentResource::class);
    }

    public function store(SaveDepartmentRequest $request): JsonResponse
    {
        Gate::authorize('create', Department::class);

        $department = Department::create(['name' => (string) $request->validated()['name']]);

        return ApiResponse::created(new DepartmentResource($department), 'Department created.');
    }

    public function update(SaveDepartmentRequest $request, Department $department): JsonResponse
    {
        Gate::authorize('update', $department);

        $department->forceFill(['name' => (string) $request->validated()['name']])->save();

        return ApiResponse::updated(new DepartmentResource($department), 'Department updated.');
    }

    public function destroy(Request $request, Department $department): JsonResponse
    {
        Gate::authorize('delete', $department);

        $department->delete();

        return ApiResponse::deleted('Department deleted.');
    }

    public function assignManager(AssignManagerRequest $request, Department $department): JsonResponse
    {
        Gate::authorize('update', $department);

        $memberId = $request->validated()['member_id'] ?? null;
        $managerId = $this->resolveManagerUserId(is_string($memberId) ? $memberId : null, (int) $department->getAttribute('organization_id'));
        $department->forceFill(['manager_id' => $managerId])->save();

        return ApiResponse::updated(new DepartmentResource($department), 'Manager assigned.');
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
