<?php

namespace App\Domains\Authoring\Policies;

use App\Domains\Authoring\Enums\AuthoringPermission;
use App\Domains\Authoring\Models\ContentVersion;
use App\Platform\Identity\Contracts\Actor;
use App\Platform\Identity\Contracts\CourseAccessPort;
use App\Platform\Shared\Policies\BasePolicy;

/**
 * P2/W03 - Authorizes version operations through the version's course, reusing the single course
 * ownership rule via CourseAccessPort (so this domain never imports the Course model).
 *
 * Tiers:
 *  - view / clone      -> may manage the course's content (owner, admin, super_admin)
 *  - restore / rollback -> STRONGER: managing the course AND holding the global manage-curriculum
 *                          permission (or super_admin via before()). A plain assigned trainer can
 *                          read history and clone, but a destructive draft replacement is admin-tier.
 *
 * Create (course-scoped, no instance) and fork (destination course) are authorized in the
 * controller via CourseAccessPort, since there is no ContentVersion instance to bind at that point.
 */
class ContentVersionPolicy extends BasePolicy
{
    public function before(mixed $user, string $ability): ?bool
    {
        if ($user instanceof Actor && $user->hasRole('super_admin')) {
            return true;
        }

        return null;
    }

    public function view(Actor $user, ContentVersion $version): bool
    {
        return $this->managesCourse($user, $version);
    }

    public function clone(Actor $user, ContentVersion $version): bool
    {
        return $this->managesCourse($user, $version);
    }

    public function restore(Actor $user, ContentVersion $version): bool
    {
        return $this->managesCourse($user, $version) && $this->hasStrongPermission($user);
    }

    public function rollback(Actor $user, ContentVersion $version): bool
    {
        return $this->managesCourse($user, $version) && $this->hasStrongPermission($user);
    }

    private function managesCourse(Actor $user, ContentVersion $version): bool
    {
        return app(CourseAccessPort::class)->canManageContent($user, (int) $version->course_id);
    }

    private function hasStrongPermission(Actor $user): bool
    {
        return $user->hasPermission(AuthoringPermission::ManageCurriculum->value);
    }
}
