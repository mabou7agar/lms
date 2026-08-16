<?php

declare(strict_types=1);

namespace App\Domains\Qna\Services;

use App\Domains\Qna\Enums\QuestionStatus;
use App\Domains\Qna\Models\CourseQuestion;
use App\Domains\Qna\Models\QnaSetting;
use Illuminate\Support\Carbon;

/**
 * How responsive a course team actually is.
 *
 * Every figure is computed over `first_response_at` / `first_response_minutes`, which are stamped
 * only by an INSTRUCTOR's first answer — so a course cannot look attentive because its learners
 * help each other. Questions the team has not reached yet are excluded from the average rather than
 * counted as zero: an unanswered question is not a fast answer, and letting it drag the mean down
 * would make "average response time" mean two different things at once. It is reported separately as
 * `unanswered`, which is the number that actually needs acting on.
 *
 * The response RATE is the share of questions that have had an instructor reply at all — the honest
 * denominator being every question asked in the window, answered or not.
 */
class QnaMetricsService
{
    /**
     * @param  list<int>  $courseIds  the courses whose Q&A the caller is entitled to see.
     * @return array{
     *     questions: int, answered: int, unanswered: int, overdue: int,
     *     response_rate: float, avg_first_response_minutes: int|null,
     *     median_first_response_minutes: int|null, sla_hours: int
     * }
     */
    public function forCourses(array $courseIds, ?Carbon $from = null, ?Carbon $to = null): array
    {
        $slaHours = QnaSetting::current()->response_sla_hours;

        if ($courseIds === []) {
            return $this->empty($slaHours);
        }

        $base = fn () => CourseQuestion::query()
            ->whereIn('course_id', $courseIds)
            ->where('status', '!=', QuestionStatus::Hidden->value)
            ->when($from !== null, fn ($q) => $q->where('created_at', '>=', $from))
            ->when($to !== null, fn ($q) => $q->where('created_at', '<=', $to));

        $questions = (int) $base()->count();

        if ($questions === 0) {
            return $this->empty($slaHours);
        }

        $answered = (int) $base()->whereNotNull('first_response_at')->count();
        $unanswered = (int) $base()->awaitingResponse()->count();
        $overdue = (int) $base()->overdue($slaHours)->count();

        $minutes = $base()
            ->whereNotNull('first_response_minutes')
            ->pluck('first_response_minutes')
            ->map(static fn ($m): int => (int) $m)
            ->sort()
            ->values();

        return [
            'questions' => $questions,
            'answered' => $answered,
            'unanswered' => $unanswered,
            'overdue' => $overdue,
            'response_rate' => round($answered / $questions, 4),
            'avg_first_response_minutes' => $minutes->isEmpty() ? null : (int) round($minutes->avg()),
            // The median is reported alongside the mean because one question answered three weeks
            // late drags an average somewhere nobody recognises.
            'median_first_response_minutes' => $minutes->isEmpty() ? null : (int) $minutes[intdiv($minutes->count(), 2)],
            'sla_hours' => $slaHours,
        ];
    }

    /**
     * @return array{
     *     questions: int, answered: int, unanswered: int, overdue: int,
     *     response_rate: float, avg_first_response_minutes: null,
     *     median_first_response_minutes: null, sla_hours: int
     * }
     */
    private function empty(int $slaHours): array
    {
        return [
            'questions' => 0,
            'answered' => 0,
            'unanswered' => 0,
            'overdue' => 0,
            'response_rate' => 0.0,
            'avg_first_response_minutes' => null,
            'median_first_response_minutes' => null,
            'sla_hours' => $slaHours,
        ];
    }
}
