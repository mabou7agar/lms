<?php

declare(strict_types=1);

namespace App\Domains\Qna\Policies;

use App\Domains\Qna\Models\QuestionAnswer;
use App\Platform\Identity\Contracts\Actor;
use App\Platform\Identity\Contracts\CourseAccessPort;
use App\Platform\Shared\Learning\Contracts\CourseEnrollmentPort;
use App\Platform\Shared\Policies\BasePolicy;

/**
 * Answer authorization. Viewing/reporting an answer requires participation in its question's course;
 * editing/deleting is author-only (super_admin bypasses via before()). The course is reached through
 * the parent question, so this policy never imports Catalog's Course model.
 */
class QuestionAnswerPolicy extends BasePolicy
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

    public function view(Actor $user, QuestionAnswer $answer): bool
    {
        return $this->participatesIn($user, $this->courseId($answer));
    }

    public function report(Actor $user, QuestionAnswer $answer): bool
    {
        return $this->participatesIn($user, $this->courseId($answer));
    }

    public function update(Actor $user, QuestionAnswer $answer): bool
    {
        return (int) $answer->user_id === $user->actorId();
    }

    public function delete(Actor $user, QuestionAnswer $answer): bool
    {
        return (int) $answer->user_id === $user->actorId();
    }

    private function courseId(QuestionAnswer $answer): int
    {
        // The question is tenant-scoped: a cross-tenant question resolves to null, which yields a
        // non-existent course id (0) here and therefore a clean deny below — never a 500.
        $question = $answer->question;

        return $question === null ? 0 : (int) $question->course_id;
    }

    private function participatesIn(Actor $user, int $courseId): bool
    {
        return $this->access->canManageContent($user, $courseId)
            || $this->enrollment->hasCourseAccess($courseId, $user->actorId());
    }
}
