<?php

namespace App\Platform\Identity\Http\Resources;

use App\Platform\Identity\Models\User;
use App\Platform\Shared\Enterprise\Contracts\OrgManagerCheckPort;
use App\Platform\Shared\Resources\BaseResource;
use Illuminate\Http\Request;

/**
 * @property User $resource
 */
class UserResource extends BaseResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->public_id,
            'name' => $this->resource->name,
            'email' => $this->resource->email,
            'phone' => $this->resource->phone,
            'locale' => $this->resource->locale,
            'is_active' => $this->resource->is_active,
            'email_verified' => $this->resource->email_verified_at !== null,
            'phone_verified' => $this->resource->phone_verified_at !== null,
            'mfa_enabled' => $this->resource->mfa_enabled,
            'roles' => $this->resource->getRoleNames(),
            // Enterprise manager-portal UI hint (owner/admin or department/team manager in any org),
            // resolved through the Shared port so Identity never imports a CRM model. Only meaningful
            // for the caller's own profile; authorization itself stays server-side (ManagerScope).
            'is_org_manager' => $this->resolveIsOrgManager($request),
            'profile' => new ProfileResource($this->whenLoaded('profile')),
            'created_at' => $this->resource->created_at?->toIso8601String(),
        ];
    }

    /** True only for the authenticated user's OWN profile when CRM reports enterprise manager authority. */
    private function resolveIsOrgManager(Request $request): bool
    {
        $callerId = $request->user()?->getAuthIdentifier();

        if ($callerId === null || (int) $callerId !== (int) $this->resource->getKey()) {
            return false;
        }

        if (! app()->bound(OrgManagerCheckPort::class)) {
            return false;
        }

        return app(OrgManagerCheckPort::class)->managesAnyOrganization((int) $this->resource->getKey());
    }
}
