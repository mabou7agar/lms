<?php

namespace App\Domains\Reviews\Policies;

use App\Domains\Reviews\Models\CourseReview;
use App\Platform\Identity\Contracts\Actor;
use App\Platform\Identity\Contracts\CourseAccessPort;
use App\Platform\Shared\Policies\BasePolicy;

/**
 * Authorization for course reviews.
 *
 *   - view / viewAny : public (anyone; tenancy scope already hides other tenants' rows).
 *   - update         : own review only.
 *   - delete         : own review, OR a moderator (admin — super_admin handled by before()).
 *   - respond        : the course's own instructor (CourseAccessPort) — super_admin via before().
 *
 * NOTE: create() is authorized in CreateReviewAction (enrollment + not-instructor + one-per-course),
 * because those rules need the course context that a Gate `create` ability does not carry.
 *
 * before() grants super_admin everything, but ONLY fires when checks run through the Gate — so
 * controllers must authorize via Gate::forUser(...)->authorize(...), never by calling these methods
 * directly.
 */
class CourseReviewPolicy extends BasePolicy
{
    public function before(mixed $user, string $ability): ?bool
    {
        if ($user instanceof Actor && $user->hasRole('super_admin')) {
            return true;
        }

        return null;
    }

    public function viewAny(?Actor $user): bool
    {
        return true;
    }

    public function view(?Actor $user, CourseReview $review): bool
    {
        return true;
    }

    public function update(Actor $user, CourseReview $review): bool
    {
        return $review->isOwnedBy($user->actorId());
    }

    public function delete(Actor $user, CourseReview $review): bool
    {
        return $review->isOwnedBy($user->actorId()) || $this->isModerator($user);
    }

    public function respond(Actor $user, CourseReview $review): bool
    {
        return app(CourseAccessPort::class)->canManageContent($user, (int) $review->course_id);
    }

    private function isModerator(Actor $user): bool
    {
        return $user->hasRole('admin');
    }
}
