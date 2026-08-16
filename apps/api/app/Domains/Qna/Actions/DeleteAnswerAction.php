<?php

declare(strict_types=1);

namespace App\Domains\Qna\Actions;

use App\Domains\Qna\Enums\QuestionStatus;
use App\Domains\Qna\Models\CourseQuestion;
use App\Domains\Qna\Models\QuestionAnswer;
use Illuminate\Support\Facades\DB;

/**
 * Soft-deletes an answer, keeping the parent question's denormalised state consistent: the
 * answers_count is decremented (never below zero) and, if this was the accepted answer, the question
 * is un-resolved (accepted_answer_id cleared, status back to open). Ownership is enforced by
 * QuestionAnswerPolicy::delete before this runs.
 */
final class DeleteAnswerAction
{
    public function execute(QuestionAnswer $answer): void
    {
        DB::transaction(function () use ($answer): void {
            /** @var CourseQuestion|null $question */
            $question = CourseQuestion::query()->whereKey($answer->question_id)->first();

            $answer->delete();

            if ($question === null) {
                return;
            }

            if ($question->answers_count > 0) {
                $question->decrement('answers_count');
            }

            if ((int) $question->accepted_answer_id === (int) $answer->id) {
                $question->accepted_answer_id = null;
                // Withdrawing the accepted answer reopens the question only if nothing else is left
                // to read. The response clock is never rewound: an instructor did reply, and
                // deleting a post afterwards does not un-happen it.
                $question->status = $question->answers_count > 0
                    ? QuestionStatus::Answered
                    : QuestionStatus::Open;
                $question->save();
            }
        });
    }
}
