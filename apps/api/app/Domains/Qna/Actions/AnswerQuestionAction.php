<?php

declare(strict_types=1);

namespace App\Domains\Qna\Actions;

use App\Domains\Qna\Events\QuestionAnswered;
use App\Domains\Qna\Models\CourseQuestion;
use App\Domains\Qna\Models\QuestionAnswer;
use App\Platform\Identity\Contracts\Actor;
use App\Platform\Identity\Contracts\CourseAccessPort;
use App\Platform\Shared\Html\HtmlSanitizer;
use App\Platform\Shared\Learning\Contracts\CourseEnrollmentPort;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Posts an answer. Answerable by an enrolled learner OR a course instructor/super_admin. `is_instructor`
 * is DERIVED here from course-management access (CourseAccessPort resolves the same ownership rule as
 * curriculum authoring, incl. super_admin/permission) and FROZEN on the row — a badge of who answered.
 * Course access is re-checked so a non-participant cannot answer even a question they can somehow name.
 *
 * Maintains the denormalised answers_count and emits QuestionAnswered for the integrator to notify on.
 */
final class AnswerQuestionAction
{
    public function __construct(
        private readonly HtmlSanitizer $sanitizer,
        private readonly CourseEnrollmentPort $enrollment,
        private readonly CourseAccessPort $access,
    ) {}

    public function execute(Actor $author, CourseQuestion $question, string $body): QuestionAnswer
    {
        $userId = $author->actorId();
        $courseId = (int) $question->course_id;

        $isInstructor = $this->access->canManageContent($author, $courseId);

        if (! $isInstructor && ! $this->enrollment->hasCourseAccess($courseId, $userId)) {
            throw new AccessDeniedHttpException('You do not have access to this course.');
        }

        return DB::transaction(function () use ($question, $userId, $body, $isInstructor, $courseId): QuestionAnswer {
            $answer = QuestionAnswer::make([
                'question_id' => $question->id,
                'user_id' => $userId,
                'body' => $this->sanitizer->sanitize($body),
            ]);
            $answer->is_instructor = $isInstructor;
            $answer->save();

            $question->increment('answers_count');

            QuestionAnswered::dispatch(
                (int) $answer->id,
                (int) $question->id,
                $courseId,
                $userId,
                (int) $question->user_id,
                $isInstructor,
            );

            return $answer;
        });
    }
}
