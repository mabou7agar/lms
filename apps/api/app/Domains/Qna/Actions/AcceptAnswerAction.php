<?php

declare(strict_types=1);

namespace App\Domains\Qna\Actions;

use App\Domains\Qna\Enums\QuestionStatus;
use App\Domains\Qna\Events\AnswerAccepted;
use App\Domains\Qna\Models\CourseQuestion;
use App\Domains\Qna\Models\QuestionAnswer;
use App\Platform\Shared\Analytics\AnalyticsEventName;
use App\Platform\Shared\Analytics\Contracts\AnalyticsEventRecorder;
use App\Platform\Shared\Analytics\Data\AnalyticsEventInput;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Marks an answer as the accepted one and resolves the question. Authorization (question author or
 * course instructor/super_admin) is enforced by CourseQuestionPolicy::accept before this runs.
 *
 * Exactly one answer may be accepted: any previously-accepted answer on the same question is cleared
 * inside the transaction before the new one is set, so the invariant holds under concurrency.
 */
final class AcceptAnswerAction
{
    public function __construct(private readonly AnalyticsEventRecorder $analytics) {}

    public function execute(CourseQuestion $question, QuestionAnswer $answer, int $acceptedByUserId): CourseQuestion
    {
        // IDOR / integrity guard: the answer must belong to the question being resolved.
        if ((int) $answer->question_id !== (int) $question->id) {
            throw ValidationException::withMessages([
                'answer' => 'This answer does not belong to the question.',
            ]);
        }

        return DB::transaction(function () use ($question, $answer, $acceptedByUserId): CourseQuestion {
            QuestionAnswer::query()
                ->where('question_id', $question->id)
                ->where('accepted', true)
                ->update(['accepted' => false]);

            $answer->accepted = true;
            $answer->save();

            $question->accepted_answer_id = (int) $answer->id;
            $question->status = QuestionStatus::Resolved;
            $question->save();

            AnswerAccepted::dispatch(
                (int) $answer->id,
                (int) $question->id,
                (int) $question->course_id,
                (int) $answer->user_id,
                $acceptedByUserId,
            );

            // Keyed on the QUESTION: a thread resolves once, even if the asker changes their mind
            // about which answer did it.
            $this->analytics->record(new AnalyticsEventInput(
                name: AnalyticsEventName::QnaQuestionAccepted->value,
                userId: $acceptedByUserId,
                courseId: (int) $question->course_id,
                dedupKey: 'qna_accepted:'.$question->public_id,
            ));

            return $question;
        });
    }
}
