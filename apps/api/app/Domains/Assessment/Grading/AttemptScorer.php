<?php

namespace App\Domains\Assessment\Grading;

use App\Domains\Assessment\Enums\AttemptStatus;
use App\Domains\Assessment\Events\AttemptGraded;
use App\Domains\Assessment\Models\AssessmentAnswer;
use App\Domains\Assessment\Models\AssessmentAttempt;
use App\Domains\Assessment\Models\AssessmentQuestion;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Turns graded answers into an attempt score.
 *
 * Scoring policy lives HERE, not in the graders: a grader says how much of a question the learner
 * got right (a ratio), and this class decides what that is worth — per-question weight, negative
 * marking, pass mark. That split is what lets penalty rules change without auditing five graders.
 *
 * The denominator is the points available in THIS attempt, taken from the frozen `question_order`,
 * not the assessment's full question list — otherwise a randomised subset would be scored out of
 * questions the learner was never shown.
 */
class AttemptScorer
{
    public function __construct(private readonly GraderRegistry $registry) {}

    /**
     * Grade every answer in the attempt and persist the result. Idempotent: re-running produces
     * the same score, so a retried job or a double-submit cannot corrupt a result.
     */
    public function score(AssessmentAttempt $attempt): AssessmentAttempt
    {
        $assessment = $attempt->assessment()->firstOrFail();

        // Keyed by public_id (a string), because the frozen question_order stores public ids.
        /** @var Collection<string, AssessmentQuestion> $questions */
        $questions = AssessmentQuestion::query()
            ->with('options')
            ->where('assessment_id', $assessment->id)
            ->whereIn('public_id', $attempt->questionOrder())
            ->get()
            ->keyBy('public_id');

        /** @var Collection<int, AssessmentAnswer> $answers */
        $answers = $attempt->answers()->get()->keyBy('question_id');

        $earned = 0.0;
        $available = 0.0;
        $awaitingReview = false;

        DB::transaction(function () use ($questions, $answers, $attempt, $assessment, &$earned, &$available, &$awaitingReview): void {
            foreach ($questions as $question) {
                $points = (float) $question->points;
                $available += $points;

                // No row at all means the learner never touched the question — score it as an
                // explicit empty answer so the attempt still has a complete, auditable answer set.
                $answer = $answers->get($question->id) ?? new AssessmentAnswer([
                    'attempt_id' => $attempt->id,
                    'question_id' => $question->id,
                    'response' => null,
                ]);

                $result = $this->registry->for($question->type)->grade($question, $answer);

                if ($result->requiresManualReview) {
                    $awaitingReview = true;
                    $answer->fill(['is_correct' => null, 'points_awarded' => null, 'graded_at' => null]);
                    $answer->save();

                    continue;
                }

                $awarded = $points * $result->ratio;

                // Penalty only ever applies to a genuinely wrong answer, and only when the
                // assessment opts in. An unanswered question is not punished — guessing deterrence
                // should not become a penalty for running out of time.
                if ($result->earnedNothing() && $assessment->negative_marking && $answer->response !== null) {
                    $awarded = -1 * (float) $question->negative_points;
                }

                $earned += $awarded;

                $answer->fill([
                    'is_correct' => $result->isCorrect,
                    'points_awarded' => round($awarded, 2),
                    'graded_at' => now(),
                ]);
                $answer->save();
            }

            // Negative marking can drive a total below zero; a negative overall score is not a
            // meaningful result to show a learner, so the attempt floors at zero.
            $earned = max(0.0, $earned);
            $percentage = $available > 0.0 ? round(($earned / $available) * 100, 2) : 0.0;

            // An attempt that ran out of time keeps the Expired status even though it has been
            // scored — it is still final, and preserving it is the only record that the learner
            // was cut off rather than choosing to submit. Overwriting it with Graded would erase
            // the one fact a disputed result turns on.
            $finalStatus = match (true) {
                $attempt->status === AttemptStatus::Expired => AttemptStatus::Expired,
                $awaitingReview => AttemptStatus::AwaitingReview,
                default => AttemptStatus::Graded,
            };

            $attempt->fill([
                'status' => $finalStatus,
                'score' => round($earned, 2),
                'max_score' => round($available, 2),
                'percentage' => $percentage,
                // Null while a human still owes a grade, and null for an assessment with no pass mark.
                'passed' => $awaitingReview || $assessment->passing_score === null
                    ? null
                    : $percentage >= $assessment->passing_score,
                'graded_at' => $awaitingReview ? null : now(),
            ]);
            $attempt->save();
        });

        $attempt = $attempt->refresh();

        // Additive pass/fail signal for learner notifications. Only fired once the outcome is decided:
        // an attempt still awaiting manual review, or an assessment with no pass mark, leaves `passed`
        // null and emits nothing. Scoring/persistence above is untouched — this only announces the
        // committed result. Dedup on the attempt id (in the notification wiring) makes a re-score safe.
        if ($attempt->passed !== null) {
            AttemptGraded::dispatch(
                (int) $attempt->id,
                (int) $attempt->user_id,
                (int) $assessment->id,
                $assessment->course_id === null ? null : (int) $assessment->course_id,
                (bool) $attempt->passed,
                $attempt->score === null ? null : (float) $attempt->score,
            );
        }

        return $attempt;
    }
}
