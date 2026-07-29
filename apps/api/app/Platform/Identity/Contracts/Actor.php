<?php

namespace App\Platform\Identity\Contracts;

use App\Platform\Identity\Contracts\Data\UserRef;
use Illuminate\Contracts\Auth\Access\Authorizable;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * The authenticated principal, as seen by authorization code (policies and gates).
 *
 * Purpose: let policies type-hint an interface instead of the concrete
 * App\Platform\Identity\Models\User, without breaking Laravel's Gate. Laravel injects the
 * authenticated user (an Authenticatable & Authorizable) into policy methods; by extending both
 * framework contracts, a policy typed `Actor $user` still receives that principal and
 * `$user->can(...)` (from Authorizable) keeps working unchanged.
 *
 * The User model will (in a later phase — NOT this one) declare `implements Actor`; it already
 * satisfies every member (extends Authenticatable, is Authorizable via the framework base, has
 * Spatie hasRole, and getKey() backs actorId()). This interface adds NO authorization *logic* —
 * only the shape authorization code depends on.
 */
interface Actor extends Authenticatable, Authorizable
{
    /** Stable internal user id (== (int) getKey()); replaces `$user->id` in ownership checks. */
    public function actorId(): int;

    /**
     * Role membership check (Spatie-compatible), so policies need not import the model or trait.
     *
     * @param  string|array<int, string>  $roles
     */
    public function hasRole($roles): bool;

    /**
     * Permission check that works under EVERY auth guard.
     *
     * `$user->can()` is not a safe substitute. Spatie resolves a guard when checking a permission,
     * and under `auth:sanctum` that guard is not the `web` one permissions are registered against
     * — so `can()` answers false even for a user who genuinely holds the permission. Implementers
     * must pin the guard explicitly and return false (never throw) for an unknown permission.
     */
    public function hasPermission(string $permission): bool;

    /** Convenience projection of this principal to a boundary-safe UserRef. */
    public function toUserRef(): UserRef;
}
