<?php

namespace App\Platform\Identity\Http\Resources;

use App\Platform\Identity\Models\User;
use App\Platform\Shared\Enterprise\Contracts\OrgManagerCheckPort;
use App\Platform\Shared\Resources\BaseResource;
use Illuminate\Http\Request;
use Spatie\Permission\Models\Permission;

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
            // The caller's own effective permissions, for UI that would otherwise advertise a
            // destination the caller cannot use. Scoped to self exactly like is_org_manager below,
            // because one user's permission set is not another user's business. This is a HINT:
            // every endpoint still authorizes for itself, and hiding a link removes no guard.
            'permissions' => $this->resolveOwnPermissions($request),
            // Enterprise manager-portal UI hint (owner/admin or department/team manager in any org),
            // resolved through the Shared port so Identity never imports a CRM model. Only meaningful
            // for the caller's own profile; authorization itself stays server-side (ManagerScope).
            'is_org_manager' => $this->resolveIsOrgManager($request),
            'profile' => new ProfileResource($this->whenLoaded('profile')),
            'created_at' => $this->resource->created_at?->toIso8601String(),
        ];
    }

    /**
     * The permission names this user holds, but only when they are asking about themselves.
     *
     * @return list<string>|null null for anyone else's profile — absent rather than empty, so a
     *                           client can tell "not disclosed" from "holds nothing".
     */
    private function resolveOwnPermissions(Request $request): ?array
    {
        $callerId = $request->user()?->getAuthIdentifier();

        if ($callerId === null || (int) $callerId !== (int) $this->resource->getKey()) {
            return null;
        }

        // super_admin holds no explicit permission rows: every policy grants it through a before()
        // hook instead. Reporting what Spatie has on file would therefore tell the one role that can
        // do everything that it can do nothing — and a UI reading this hint would hide exactly the
        // screens that role exists to use. What it effectively holds is everything.
        if ($this->resource->hasRole('super_admin')) {
            /** @var list<string> $all */
            $all = Permission::query()->orderBy('name')->pluck('name')
                ->map(static fn (mixed $name): string => (string) $name)
                ->values()
                ->all();

            return $all;
        }

        /** @var list<string> $names */
        $names = $this->resource->getAllPermissions()
            ->pluck('name')
            ->map(static fn (mixed $name): string => (string) $name)
            ->values()
            ->all();

        return $names;
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
