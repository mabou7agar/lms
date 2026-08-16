<?php

declare(strict_types=1);

namespace App\Domains\Qna\Actions;

use App\Domains\Qna\Models\QuestionAnswer;
use Illuminate\Support\Facades\DB;

/**
 * Marking the course's authoritative answer, and withdrawing that mark.
 *
 * "Official" is the course saying this is correct; "accepted" is the asker saying it solved their
 * problem. They are deliberately separate claims — collapsing them would let an instructor speak for
 * the learner. Only one answer per question is official, so marking a new one clears the previous
 * inside a transaction: a thread with two authoritative answers is a thread with none.
 */
final class MarkOfficialAnswerAction
{
    public function mark(QuestionAnswer $answer): QuestionAnswer
    {
        DB::transaction(function () use ($answer): void {
            QuestionAnswer::where('question_id', $answer->question_id)
                ->where('id', '!=', $answer->id)
                ->update(['is_official' => false]);

            $answer->forceFill(['is_official' => true])->save();
        });

        return $answer->refresh();
    }

    public function unmark(QuestionAnswer $answer): QuestionAnswer
    {
        $answer->forceFill(['is_official' => false])->save();

        return $answer->refresh();
    }
}
