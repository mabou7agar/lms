<?php

namespace App\Platform\Identity\Http\Controllers\Api\V1;

use App\Platform\Identity\Actions\Sso\CreateSsoDomainMappingAction;
use App\Platform\Identity\Enums\SsoDomainMode;
use App\Platform\Identity\Http\Requests\StoreSsoDomainRequest;
use App\Platform\Identity\Http\Requests\UpdateSsoDomainRequest;
use App\Platform\Identity\Http\Resources\SsoDomainMappingResource;
use App\Platform\Identity\Models\SsoDomainMapping;
use App\Platform\Identity\Models\User;
use App\Platform\Shared\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Gate;

/**
 * Org-admin, TENANT-SCOPED CRUD for SSO email-domain mappings. Every read/write is confined to the
 * acting admin's organization: org A's admin can never see or modify org B's rows. The org-admin gate
 * (ManageUsers permission + organization membership) and per-row ownership are enforced by
 * SsoDomainMappingPolicy; verification is a super_admin-only stub (no DNS/email verification is built).
 */
class SsoDomainMappingController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        Gate::authorize('manage', SsoDomainMapping::class);

        $mappings = SsoDomainMapping::query()
            ->forOrganization((int) $this->user($request)->organizationId())
            ->latest('id')
            ->get();

        return ApiResponse::success(SsoDomainMappingResource::collection($mappings));
    }

    public function store(StoreSsoDomainRequest $request, CreateSsoDomainMappingAction $action): JsonResponse
    {
        Gate::authorize('manage', SsoDomainMapping::class);

        $admin = $this->user($request);

        $mapping = $action->execute(
            (int) $admin->organizationId(),
            (int) $admin->getKey(),
            (string) $request->validated('domain'),
            SsoDomainMode::from((string) $request->validated('mode')),
        );

        return ApiResponse::created(new SsoDomainMappingResource($mapping), 'Domain added.');
    }

    public function update(UpdateSsoDomainRequest $request, SsoDomainMapping $ssoDomainMapping): JsonResponse
    {
        Gate::authorize('update', $ssoDomainMapping);

        $ssoDomainMapping->update(['mode' => (string) $request->validated('mode')]);

        return ApiResponse::updated(new SsoDomainMappingResource($ssoDomainMapping), 'Domain updated.');
    }

    public function destroy(SsoDomainMapping $ssoDomainMapping): JsonResponse
    {
        Gate::authorize('delete', $ssoDomainMapping);

        $ssoDomainMapping->delete();

        return ApiResponse::deleted('Domain removed.');
    }

    /**
     * Toggle the verification stub. super_admin-only (SsoDomainMappingPolicy::verify denies everyone
     * else, super_admin bypasses via before()). This is a manual flag — no DNS/email check is run.
     */
    public function verify(Request $request, SsoDomainMapping $ssoDomainMapping): JsonResponse
    {
        Gate::authorize('verify', $ssoDomainMapping);

        $verified = (bool) $request->boolean('verified', true);
        $ssoDomainMapping->update(['verified_at' => $verified ? now() : null]);

        return ApiResponse::updated(new SsoDomainMappingResource($ssoDomainMapping), 'Domain verification updated.');
    }

    private function user(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }
}
