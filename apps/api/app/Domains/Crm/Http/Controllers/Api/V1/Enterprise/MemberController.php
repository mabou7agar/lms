<?php

namespace App\Domains\Crm\Http\Controllers\Api\V1\Enterprise;

use App\Domains\Crm\Actions\Organization\ChangeMemberRoleAction;
use App\Domains\Crm\Actions\Organization\DeactivateMemberAction;
use App\Domains\Crm\Actions\Organization\RemoveMemberAction;
use App\Domains\Crm\Enums\MemberRole;
use App\Domains\Crm\Http\Requests\Enterprise\ChangeMemberRoleRequest;
use App\Domains\Crm\Http\Resources\OrganizationMemberResource;
use App\Domains\Crm\Models\OrganizationMember;
use App\Platform\Shared\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Member management for the enterprise portal. Listing is scoped to the caller's ManagerScope (a
 * department/team manager sees only their members; an owner/admin sees the org). Mutations
 * (remove/role/deactivate) require owner/admin authority and target a member the scope covers.
 * Removing/deactivating a member releases their seats (in the actions).
 */
class MemberController extends EnterpriseController
{
    public function index(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', OrganizationMember::class);

        $scope = $this->scope($request);
        $perPage = max(1, min((int) $request->integer('per_page', 25), 100));

        $query = OrganizationMember::query()->where('organization_id', $scope->organizationId);

        // A department/team manager sees only the members their scope covers; owner/admin see all.
        if (! $scope->viewAll) {
            $query->whereIn('id', $scope->memberIds ?: [0]);
        }

        $members = $query->latest('id')->paginate($perPage)->withQueryString();

        return ApiResponse::paginated($members, OrganizationMemberResource::class);
    }

    public function remove(Request $request, OrganizationMember $member, RemoveMemberAction $action): JsonResponse
    {
        Gate::authorize('manage', $member);

        $action->execute($member);

        return ApiResponse::deleted('Member removed.');
    }

    public function changeRole(ChangeMemberRoleRequest $request, OrganizationMember $member, ChangeMemberRoleAction $action): JsonResponse
    {
        Gate::authorize('manage', $member);

        $updated = $action->execute($member, MemberRole::from((string) $request->validated()['role']));

        return ApiResponse::updated(new OrganizationMemberResource($updated), 'Role updated.');
    }

    public function deactivate(Request $request, OrganizationMember $member, DeactivateMemberAction $action): JsonResponse
    {
        Gate::authorize('manage', $member);

        $action->execute($member);

        return ApiResponse::updated(new OrganizationMemberResource($member->fresh()), 'Member deactivated.');
    }
}
