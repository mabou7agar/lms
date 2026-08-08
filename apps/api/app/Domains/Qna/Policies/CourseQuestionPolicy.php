<?php

declare(strict_types=1);

namespace App\Domains\Qna\Policies;

use App\Domains\Qna\Models\CourseQuestion;
use App\Platform\Identity\Contracts\Actor;
use App\Platform\Identity\Contracts\CourseAccessPort;
use App\Platform\Shared\Learning\Contracts\CourseEnrollmentPort;
use App\Platform\Shared\Policies\BasePolicy;

/**
 * Q&A is visible to course PARTICIPANTS only: an enrolled/entitled learner or a course
 * instructor/super_admin — never the anonymous public (a question can quote paid course material).
 *
 * Authorship rules:
 *   - view/report : any participant.
 *   - update/delete: the author only (super_admin bypasses via before()).
 *   - pin         : course instructor/super_admin.
 *   - accept      : the question's author OR a course instructor/super_admin.
 *
 * super_admin is a genuine bypass via before(); it fires through the Gate, which is how controllers
 * invoke every ability here. Enrollment + ownership are resolved through Platform ports, so this
 * policy never imports Catalog's Course or Learning's Enrollment model.
 */
class CourseQuestionPolicy extends BasePolicy
{
    public function __construct(
        private readonly CourseAccessPort $access,
        private readonly CourseEnrollmentPort $enrollment,
    ) {}

    public function before(mixed $user, string $ability): ?bool
    {
        if ($user instanceof Actor && $user->hasRole('super_admin')) {
            return true;
        }

        return null;
    }

    public function view(Actor $user, CourseQuestion $question): bool
    {
        return $this->participatesIn($user, (int) $question->course_id);
    }

    public function report(Actor $user, CourseQuestion $question): bool
    {
        return $this->participatesIn($user, (int) $question->course_id);
    }

    public function update(Actor $user, CourseQuestion $question): bool
    {
        return $this->owns($user, $question);
    }

    public function delete(Actor $user, CourseQuestion $question): bool
    {
        return $this->owns($user, $question);
    }

    public function pin(Actor $user, CourseQuestion $question): bool
    {
        return $this->access->canManageContent($user, (int) $question->course_id);
    }

    public function accept(Actor $user, CourseQuestion $question): bool
    {
        return $this->owns($user, $question)
            || $this->access->canManageContent($user, (int) $question->course_id);
    }

    private function owns(Actor $user, CourseQuestion $question): bool
    {
        return (int) $question->user_id === $user->actorId();
    }

    private function participatesIn(Actor $user, int $courseId): bool
    {
        return $this->access->canManageContent($user, $courseId)
            || $this->enrollment->hasCourseAccess($courseId, $user->actorId());
    }
}
