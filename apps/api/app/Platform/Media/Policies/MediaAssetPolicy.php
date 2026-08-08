<?php

namespace App\Platform\Media\Policies;

use App\Platform\Identity\Contracts\Actor;
use App\Platform\Identity\Contracts\CourseAccessPort;
use App\Platform\Media\Models\MediaAsset;
use App\Platform\Shared\Media\Contracts\MediaEnrollmentPort;
use App\Platform\Shared\Policies\BasePolicy;
use App\Platform\Shared\Tenancy\TenantContext;

/**
 * P2/W04 - Authorizes media operations.
 *
 * Management (view/update/retry/delete/attach) is granted to the asset OWNER, or to anyone who may
 * manage the asset's course (CourseAccessPort — the single course-ownership rule; this policy never
 * imports the Course model). super_admin bypasses via before().
 *
 * Playback is stricter: the asset must be READY, and the viewer must be the owner, may manage the
 * course, or has course access through enrollment/preview/publication (delegated to
 * MediaEnrollmentPort). A learner therefore never plays unpublished/unready media.
 *
 * TENANCY (T1 Option-N): every management and playback edge is additionally gated on tenant
 * visibility. A GLOBAL asset (organization_id NULL) is visible to everyone (the platform catalog); an
 * org-owned asset is authorizable ONLY under its owning tenant. This is defense-in-depth behind
 * MediaAsset's SharedOrOwnedTenantScope (which already hides another org's assets from route binding).
 * super_admin bypasses the whole policy via before().
 */
class MediaAssetPolicy extends BasePolicy
{
    public function before(mixed $user, string $ability): ?bool
    {
        if ($user instanceof Actor && $user->hasRole('super_admin')) {
            return true;
        }

        return null;
    }

    public function view(Actor $user, MediaAsset $media): bool
    {
        return $this->manages($user, $media);
    }

    public function update(Actor $user, MediaAsset $media): bool
    {
        return $this->manages($user, $media);
    }

    public function retry(Actor $user, MediaAsset $media): bool
    {
        return $this->manages($user, $media);
    }

    public function delete(Actor $user, MediaAsset $media): bool
    {
        return $this->manages($user, $media);
    }

    public function attach(Actor $user, MediaAsset $media): bool
    {
        return $this->manages($user, $media);
    }

    public function caption(Actor $user, MediaAsset $media): bool
    {
        return $this->manages($user, $media);
    }

    /**
     * P1 - Change an asset's public visibility (PRIVATE / AUTHENTICATED / PUBLIC). Same authority as
     * other management edges: the owner or a course manager (super_admin bypasses via before()). This
     * is the gate that stops an unauthorized PRIVATE -> PUBLIC raise from a forged request.
     */
    public function setVisibility(Actor $user, MediaAsset $media): bool
    {
        return $this->manages($user, $media);
    }

    /** Learner-facing: a ready asset the viewer may access through the course. */
    public function playback(Actor $user, MediaAsset $media): bool
    {
        if (! $media->status->isPlayable()) {
            return false;
        }

        // Tenant gate FIRST, before both the manages() and the enrollment path, so a cross-tenant
        // enrolled learner can never be granted an org-owned asset.
        if (! $this->visibleToTenant($user, $media)) {
            return false;
        }

        if ($this->manages($user, $media)) {
            return true;
        }

        if ($media->course_id === null) {
            return false;
        }

        return app(MediaEnrollmentPort::class)->canAccessCourseMedia($user->actorId(), $media->course_id);
    }

    private function manages(Actor $user, MediaAsset $media): bool
    {
        // super_admin bypasses the whole policy through the Gate's before(); repeat it here so a DIRECT
        // policy call (which never fires before()) grants the same privileged cross-tenant management.
        if ($user->hasRole('super_admin')) {
            return true;
        }

        if (! $this->visibleToTenant($user, $media)) {
            return false;
        }

        if ($media->created_by === $user->actorId()) {
            return true;
        }

        return $media->course_id !== null
            && app(CourseAccessPort::class)->canManageContent($user, $media->course_id);
    }

    /**
     * T1 Option-N tenant dimension. A GLOBAL asset (organization_id NULL) is visible to every actor
     * (the shared platform catalog). An org-owned asset is visible ONLY under its owning tenant — never
     * cross-tenant, and never with no tenant resolved.
     */
    private function visibleToTenant(Actor $user, MediaAsset $media): bool
    {
        // super_admin bypasses the tenant boundary. before() already grants this through the Gate; we
        // repeat it here so a DIRECT policy call (not routed through the Gate, so before() never fires)
        // is consistent with the privileged cross-tenant access the matrix requires.
        if ($user->hasRole('super_admin')) {
            return true;
        }

        if ($media->organization_id === null) {
            return true;
        }

        $tenantId = app(TenantContext::class)->id();

        return $tenantId !== null && $media->belongsToTenant($tenantId);
    }
}
