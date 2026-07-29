<?php

namespace App\Platform\Media\Policies;

use App\Platform\Identity\Contracts\Actor;
use App\Platform\Identity\Contracts\CourseAccessPort;
use App\Platform\Media\Models\MediaAsset;
use App\Platform\Shared\Media\Contracts\MediaEnrollmentPort;
use App\Platform\Shared\Policies\BasePolicy;

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

    /** Learner-facing: a ready asset the viewer may access through the course. */
    public function playback(Actor $user, MediaAsset $media): bool
    {
        if (! $media->status->isPlayable()) {
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
        if ($media->created_by === $user->actorId()) {
            return true;
        }

        return $media->course_id !== null
            && app(CourseAccessPort::class)->canManageContent($user, $media->course_id);
    }
}
