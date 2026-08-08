<?php

declare(strict_types=1);

namespace App\Domains\Qna\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * A new answer was posted to a course question. Emitted by AnswerQuestionAction. The integrator wires
 * this to the Notifications context (notify the question's author, and optionally other participants)
 * — this domain only publishes the fact; it never imports the notification machinery.
 *
 * All ids are internal keys. `questionAuthorId` is carried so a listener can notify the asker without
 * re-loading the question.
 */
class QuestionAnswered
{
    use Dispatchable;

    public function __construct(
        public readonly int $answerId,
        public readonly int $questionId,
        public readonly int $courseId,
        public readonly int $answerAuthorId,
        public readonly int $questionAuthorId,
        public readonly bool $isInstructor,
    ) {}
}
