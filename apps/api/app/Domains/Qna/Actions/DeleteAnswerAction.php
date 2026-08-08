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
                $question->status = QuestionStatus::Open;
                $question->save();
            }
        });
    }
}
