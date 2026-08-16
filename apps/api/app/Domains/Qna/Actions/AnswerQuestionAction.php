<?php

declare(strict_types=1);

namespace App\Domains\Qna\Actions;

use App\Domains\Qna\Enums\QuestionStatus;
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
 *
 * It also stamps the RESPONSE CLOCK, but only for an instructor's answer: the service level is a
 * promise about the course team, and a helpful peer replying first must not make a course look
 * attentive when its instructor never appeared. The stamp is written once — a second instructor
 * answer does not reset it — and carries the elapsed minutes so SLA reporting is an aggregate over a
 * column rather than a per-row date subtraction.
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
            $answer = new QuestionAnswer([
                'question_id' => $question->id,
                'user_id' => $userId,
                'body' => $this->sanitizer->sanitize($body),
            ]);
            $answer->is_instructor = $isInstructor;
            $answer->save();

            $question->increment('answers_count');

            $this->recordResponse($question, $isInstructor);

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

    /**
     * Move the question out of Open on its first reply, and stamp the response clock when that reply
     * came from the course team. A question already answered, resolved or closed keeps the state it
     * has: only the FIRST response is a state change worth making.
     */
    private function recordResponse(CourseQuestion $question, bool $isInstructor): void
    {
        $changes = [];

        if ($isInstructor && $question->first_response_at === null) {
            $askedAt = $question->created_at ?? now();
            $changes['first_response_at'] = now();
            $changes['first_response_minutes'] = max(0, (int) round($askedAt->diffInMinutes(now())));
        }

        if ($question->status === QuestionStatus::Open) {
            $changes['status'] = QuestionStatus::Answered->value;
        }

        if ($changes !== []) {
            $question->forceFill($changes)->save();
        }
    }
}
