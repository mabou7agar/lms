<?php

namespace App\Domains\Crm\Http\Controllers\Api\V1;

use App\Domains\Crm\Actions\Organization\InviteMemberAction;
use App\Domains\Crm\Http\Requests\InviteMemberRequest;
use App\Domains\Crm\Http\Resources\OrganizationMemberResource;
use App\Domains\Crm\Http\Resources\OrganizationResource;
use App\Domains\Crm\Models\Organization;
use App\Platform\Shared\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Gate;

class OrganizationController extends Controller
{
    public function index(): JsonResponse
    {
        Gate::authorize('viewAny', Organization::class);

        $perPage = max(1, min((int) request('per_page', 15), 100));
        $orgs = Organization::query()->withCount('members')->latest('id')
            ->paginate($perPage)->withQueryString();

        return ApiResponse::paginated($orgs, OrganizationResource::class);
    }

    public function show(Organization $organization): JsonResponse
    {
        Gate::authorize('view', $organization);

        return ApiResponse::success(new OrganizationResource($organization->loadCount('members')->load('members')));
    }

    public function storeMember(InviteMemberRequest $request, Organization $organization, InviteMemberAction $action): JsonResponse
    {
        Gate::authorize('manage', $organization);

        $member = $action->execute($organization, $request->validated());

        return ApiResponse::created(new OrganizationMemberResource($member), 'Member invited.');
    }
}
