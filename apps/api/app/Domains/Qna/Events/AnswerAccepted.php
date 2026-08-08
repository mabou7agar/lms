<?php

declare(strict_types=1);

namespace App\Domains\Qna\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * A question's author (or a course instructor) accepted an answer, resolving the question. Emitted by
 * AcceptAnswerAction. The integrator may wire this to Notifications to congratulate the answerer.
 */
class AnswerAccepted
{
    use Dispatchable;

    public function __construct(
        public readonly int $answerId,
        public readonly int $questionId,
        public readonly int $courseId,
        public readonly int $answerAuthorId,
        public readonly int $acceptedByUserId,
    ) {}
}
