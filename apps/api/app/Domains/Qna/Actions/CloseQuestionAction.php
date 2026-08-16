<?php

declare(strict_types=1);

namespace App\Domains\Qna\Actions;

use App\Domains\Qna\Enums\QuestionStatus;
use App\Domains\Qna\Models\CourseQuestion;

/**
 * The course team ending a thread, and undoing that.
 *
 * Closing is distinct from the asker accepting an answer: it is the team saying "nothing further is
 * coming" — a duplicate, an off-topic question, one overtaken by a course update. Reopening returns
 * the thread to whichever state it had actually reached rather than blindly to Open, so a closed
 * thread that already had an accepted answer does not lose that fact on its way back.
 */
final class CloseQuestionAction
{
    public function close(CourseQuestion $question): CourseQuestion
    {
        $question->forceFill([
            'status' => QuestionStatus::Closed->value,
            'closed_at' => now(),
        ])->save();

        return $question;
    }

    public function reopen(CourseQuestion $question): CourseQuestion
    {
        $question->forceFill([
            'status' => $this->stateBeforeClosing($question),
            'closed_at' => null,
        ])->save();

        return $question;
    }

    private function stateBeforeClosing(CourseQuestion $question): string
    {
        if ($question->accepted_answer_id !== null) {
            return QuestionStatus::Resolved->value;
        }

        return $question->answers_count > 0
            ? QuestionStatus::Answered->value
            : QuestionStatus::Open->value;
    }
}
