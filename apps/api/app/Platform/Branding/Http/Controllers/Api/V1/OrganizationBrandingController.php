<?php

namespace App\Platform\Branding\Http\Controllers\Api\V1;

use App\Platform\Branding\Http\Requests\UpdateOrganizationBrandRequest;
use App\Platform\Branding\Models\OrganizationBrandSetting;
use App\Platform\Branding\Services\OrganizationBrandingService;
use App\Platform\Identity\Contracts\Actor;
use App\Platform\Shared\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Gate;

/**
 * Org-admin, TENANT-SCOPED management of the caller's OWN organization brand override. Both actions
 * are self-scoped — the org is derived from the authenticated principal (never a path/body param), so
 * org A can never read or write org B's brand. The org-admin gate (users-manage permission + org
 * membership) is enforced by OrganizationBrandPolicy. Reads/returns the effective public brand
 * (global merged with the org's override).
 */
class OrganizationBrandingController extends Controller
{
    public function __construct(private readonly OrganizationBrandingService $branding) {}

    /** GET /api/v1/org/branding — the caller org's effective (global-merged) brand payload. */
    public function show(Request $request): JsonResponse
    {
        Gate::authorize('manage', OrganizationBrandSetting::class);

        return ApiResponse::success(
            $this->branding->payloadForOrganization((int) $this->actor($request)->organizationId()),
        );
    }

    /** PUT/PATCH /api/v1/org/branding — upsert the caller org's brand override (validated + sanitised). */
    public function update(UpdateOrganizationBrandRequest $request): JsonResponse
    {
        Gate::authorize('manage', OrganizationBrandSetting::class);

        $orgId = (int) $this->actor($request)->organizationId();

        $this->branding->applyOverrides($orgId, $request->validated());

        return ApiResponse::updated(
            $this->branding->payloadForOrganization($orgId),
            'Brand updated.',
        );
    }

    private function actor(Request $request): Actor
    {
        /** @var Actor $user */
        $user = $request->user();

        return $user;
    }
}
