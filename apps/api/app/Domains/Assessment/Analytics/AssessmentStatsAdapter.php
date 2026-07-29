<?php

namespace App\Domains\Assessment\Analytics;

use App\Domains\Assessment\Models\AssessmentAttempt;
use App\Platform\Shared\Assessment\Contracts\AssessmentStatsPort;
use App\Platform\Shared\Assessment\Data\AssessmentPassRate;

/**
 * Assessment's implementation of the reporting port. Owns the only query outside this domain's
 * own surfaces that reads attempt outcomes, so the coupling is one auditable place.
 */
class AssessmentStatsAdapter implements AssessmentStatsPort
{
    public function passRateForLessons(array $lessonIds, ?string $from = null, ?string $to = null): AssessmentPassRate
    {
        if ($lessonIds === []) {
            return AssessmentPassRate::empty();
        }

        // `passed` is null until an attempt is graded, and null on an assessment with no pass mark.
        // Filtering on NOT NULL therefore excludes both — an attempt with no pass/fail outcome
        // cannot contribute to a pass rate in either direction. It also excludes in-progress and
        // abandoned sittings without needing to enumerate AttemptStatus cases, so a new status
        // cannot silently start counting.
        $query = AssessmentAttempt::query()
            ->whereIn('lesson_id', $lessonIds)
            ->whereNotNull('passed');

        // Windowed on submitted_at: an attempt belongs to the period its outcome was produced in,
        // not the period a learner happened to open it.
        if ($from !== null) {
            $query->whereDate('submitted_at', '>=', $from);
        }

        if ($to !== null) {
            $query->whereDate('submitted_at', '<=', $to);
        }

        $row = $query
            ->toBase()
            ->selectRaw('count(*) as graded')
            ->selectRaw('coalesce(sum(case when passed then 1 else 0 end), 0) as passed')
            ->first();

        return new AssessmentPassRate(
            gradedAttempts: (int) ($row->graded ?? 0),
            passedAttempts: (int) ($row->passed ?? 0),
        );
    }

    public function passRateForLessonGroups(array $lessonGroups, ?string $from = null, ?string $to = null): array
    {
        $result = array_fill_keys(array_keys($lessonGroups), AssessmentPassRate::empty());

        // Invert group => lessons into lesson => group so a single flat query can be folded back.
        // A lesson belongs to exactly one course, so this mapping is unambiguous.
        $groupOfLesson = [];

        foreach ($lessonGroups as $key => $lessonIds) {
            foreach ($lessonIds as $lessonId) {
                $groupOfLesson[$lessonId] = $key;
            }
        }

        if ($groupOfLesson === []) {
            return $result;
        }

        $query = AssessmentAttempt::query()
            ->whereIn('lesson_id', array_keys($groupOfLesson))
            ->whereNotNull('passed');

        if ($from !== null) {
            $query->whereDate('submitted_at', '>=', $from);
        }

        if ($to !== null) {
            $query->whereDate('submitted_at', '<=', $to);
        }

        // Grouped by lesson, then summed per caller key in PHP. Grouping by lesson keeps this
        // domain free of any notion of what the caller's key means.
        $rows = $query->toBase()
            ->selectRaw('lesson_id')
            ->selectRaw('count(*) as graded')
            ->selectRaw('coalesce(sum(case when passed then 1 else 0 end), 0) as passed')
            ->groupBy('lesson_id')
            ->get();

        $totals = [];

        foreach ($rows as $row) {
            $key = $groupOfLesson[(int) $row->lesson_id] ?? null;

            if ($key === null) {
                continue;
            }

            $totals[$key]['graded'] = ($totals[$key]['graded'] ?? 0) + (int) $row->graded;
            $totals[$key]['passed'] = ($totals[$key]['passed'] ?? 0) + (int) $row->passed;
        }

        foreach ($totals as $key => $sums) {
            $result[$key] = new AssessmentPassRate($sums['graded'], $sums['passed']);
        }

        return $result;
    }
}
