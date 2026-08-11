<?php

namespace App\Platform\Branding\Http\Controllers\Api\V1;

use App\Platform\Branding\Http\Requests\StoreCustomDomainRequest;
use App\Platform\Branding\Http\Resources\CustomDomainResource;
use App\Platform\Branding\Models\CustomDomain;
use App\Platform\Identity\Contracts\Actor;
use App\Platform\Shared\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

/**
 * Org-admin, TENANT-SCOPED CRUD for an organization's custom (white-label) domains. Every read/write
 * is confined to the acting admin's organization: org A can never see or modify org B's domains. The
 * org-admin gate + per-row ownership are enforced by CustomDomainPolicy; verification is a
 * super_admin-only stub (no DNS/ACME is run — it just toggles verified_at).
 */
class CustomDomainController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        Gate::authorize('manage', CustomDomain::class);

        $domains = CustomDomain::query()
            ->forOrganization((int) $this->actor($request)->organizationId())
            ->latest('id')
            ->get();

        return ApiResponse::success(CustomDomainResource::collection($domains));
    }

    public function store(StoreCustomDomainRequest $request): JsonResponse
    {
        Gate::authorize('manage', CustomDomain::class);

        $actor = $this->actor($request);

        $domain = CustomDomain::create([
            'organization_id' => (int) $actor->organizationId(),
            'host' => (string) $request->validated('host'),
            'is_primary' => (bool) $request->boolean('is_primary'),
            'verification_token' => Str::random(40),
            'created_by' => $actor->actorId(),
        ]);

        return ApiResponse::created(new CustomDomainResource($domain), 'Domain added.');
    }

    public function destroy(CustomDomain $customDomain): JsonResponse
    {
        Gate::authorize('delete', $customDomain);

        $customDomain->delete();

        return ApiResponse::deleted('Domain removed.');
    }

    /**
     * Toggle the verification stub. super_admin-only (CustomDomainPolicy::verify denies everyone else;
     * super_admin bypasses via before()). Manual flag only — no DNS/ACME check is run.
     */
    public function verify(Request $request, CustomDomain $customDomain): JsonResponse
    {
        Gate::authorize('verify', $customDomain);

        $verified = $request->boolean('verified', true);
        $customDomain->update(['verified_at' => $verified ? now() : null]);

        return ApiResponse::updated(new CustomDomainResource($customDomain), 'Domain verification updated.');
    }

    private function actor(Request $request): Actor
    {
        /** @var Actor $user */
        $user = $request->user();

        return $user;
    }
}
