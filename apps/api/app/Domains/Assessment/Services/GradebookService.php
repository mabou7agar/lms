<?php

namespace App\Domains\Assessment\Services;

use App\Domains\Assessment\Models\Assessment;
use App\Domains\Assessment\Models\AssessmentAttempt;
use App\Domains\Assessment\Models\Assignment;
use App\Domains\Assessment\Models\AssignmentSubmission;
use App\Domains\Assessment\Models\SubmissionGrade;
use App\Platform\Shared\Learning\Contracts\CourseEnrollmentPort;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\LengthAwarePaginator as Paginator;
use Illuminate\Support\Collection;

/**
 * Course gradebook: one row per learner, one column per gradable item — assignments AND the
 * existing auto-graded quizzes (both live in this domain, so quiz grades are read directly from
 * AssessmentAttempt; no cross-context port is needed). Each cell carries status, score, late and
 * missing flags plus pass/fail; the row summary rolls those up into an overall percentage.
 *
 * The roster comes from the enrollment port so a learner who has submitted nothing still appears
 * with "missing" cells rather than being invisible.
 */
class GradebookService
{
    public function __construct(private readonly CourseEnrollmentPort $enrollment) {}

    /**
     * A page of gradebook rows. Learners are the paginated unit; columns are constant across pages.
     *
     * @param  array<string, mixed>  $filters  currently: 'only' => 'missing'|'late' (row filter)
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    public function page(int $courseId, array $filters = [], int $perPage = 25, int $page = 1): LengthAwarePaginator
    {
        $columns = $this->columns($courseId);
        $learnerIds = $this->rosterFiltered($courseId, $columns, $filters);

        $total = count($learnerIds);
        $slice = array_slice($learnerIds, ($page - 1) * $perPage, $perPage);

        $rows = $this->rowsFor($slice, $columns);

        return new Paginator($rows, $total, $perPage, $page, [
            'path' => Paginator::resolveCurrentPath(),
        ]);
    }

    /**
     * The whole gradebook as CSV (all learners, no pagination) for export.
     */
    public function toCsv(int $courseId): string
    {
        $out = '';
        foreach ($this->streamCsv($courseId) as $line) {
            $out .= $line;
        }

        return $out;
    }

    /**
     * Yield the gradebook as CSV one line at a time, processing the roster in bounded chunks so a
     * large-enrollment course never materializes every row (nor the whole CSV string) in memory at
     * once. Output is byte-identical to the previous single-string build.
     *
     * @return \Generator<int, string>
     */
    public function streamCsv(int $courseId): \Generator
    {
        $columns = $this->columns($courseId);

        $header = ['learner_id'];
        foreach ($columns['assignments'] as $a) {
            $header[] = 'assignment:'.$a['title'];
        }
        foreach ($columns['quizzes'] as $q) {
            $header[] = 'quiz:'.$q['title'];
        }
        $header[] = 'overall_percent';

        yield $this->csvLine($header)."\n";

        foreach (array_chunk($this->roster($courseId, $columns), 200) as $chunk) {
            foreach ($this->rowsFor($chunk, $columns) as $row) {
                $line = [(string) $row['user_id']];
                foreach ($row['cells'] as $cell) {
                    $line[] = $this->csvCell($cell);
                }
                $line[] = $row['summary']['average_percent'] === null
                    ? '' : (string) $row['summary']['average_percent'];
                yield $this->csvLine($line)."\n";
            }
        }
    }

    /**
     * Column metadata for the course. Assignments = every non-deleted assignment; quizzes = every
     * assessment on the course.
     *
     * @return array{assignments: list<array<string, mixed>>, quizzes: list<array<string, mixed>>}
     */
    public function columns(int $courseId): array
    {
        $assignments = Assignment::query()
            ->where('course_id', $courseId)
            ->orderBy('id')
            ->get()
            ->map(fn (Assignment $a): array => [
                'id' => (int) $a->id,
                'public_id' => (string) $a->public_id,
                'title' => (string) $a->title,
                'max_grade' => (float) $a->max_grade,
                'passing_grade' => $a->passing_grade === null ? null : (float) $a->passing_grade,
            ])->values()->all();

        $quizzes = Assessment::query()
            ->where('course_id', $courseId)
            ->orderBy('id')
            ->get()
            ->map(fn (Assessment $q): array => [
                'id' => (int) $q->id,
                'public_id' => (string) $q->public_id,
                'title' => (string) $q->title,
                'passing_score' => $q->passing_score,
            ])->values()->all();

        return ['assignments' => $assignments, 'quizzes' => $quizzes];
    }

    /**
     * @param  list<int>  $learnerIds
     * @param  array{assignments: list<array<string, mixed>>, quizzes: list<array<string, mixed>>}  $columns
     * @return list<array<string, mixed>>
     */
    private function rowsFor(array $learnerIds, array $columns): array
    {
        if ($learnerIds === []) {
            return [];
        }

        $assignmentIds = array_map(fn ($a) => $a['id'], $columns['assignments']);
        $quizIds = array_map(fn ($q) => $q['id'], $columns['quizzes']);

        // Latest submission per (assignment, learner), with its grade — one query, grouped in PHP.
        $submissions = AssignmentSubmission::query()
            ->whereIn('assignment_id', $assignmentIds !== [] ? $assignmentIds : [0])
            ->whereIn('user_id', $learnerIds)
            ->with('grade')
            ->orderBy('attempt_no')
            ->get()
            ->groupBy(fn (AssignmentSubmission $s) => $s->assignment_id.':'.$s->user_id);

        // Latest attempt per (assessment, learner).
        $attempts = AssessmentAttempt::query()
            ->whereIn('assessment_id', $quizIds !== [] ? $quizIds : [0])
            ->whereIn('user_id', $learnerIds)
            ->orderBy('attempt_number')
            ->get()
            ->groupBy(fn (AssessmentAttempt $a) => $a->assessment_id.':'.$a->user_id);

        $rows = [];

        foreach ($learnerIds as $userId) {
            $cells = [];
            $percents = [];
            $missing = 0;
            $passed = 0;

            foreach ($columns['assignments'] as $col) {
                /** @var Collection<int, AssignmentSubmission> $group */
                $group = $submissions->get($col['id'].':'.$userId, collect());
                $cell = $this->assignmentCell($col, $group->last());
                $cells[] = $cell;

                if ($cell['missing']) {
                    $missing++;
                } elseif ($cell['percent'] !== null) {
                    $percents[] = $cell['percent'];
                }
                if ($cell['passed'] === true) {
                    $passed++;
                }
            }

            foreach ($columns['quizzes'] as $col) {
                /** @var Collection<int, AssessmentAttempt> $group */
                $group = $attempts->get($col['id'].':'.$userId, collect());
                $cell = $this->quizCell($col, $group->last());
                $cells[] = $cell;

                if ($cell['missing']) {
                    $missing++;
                } elseif ($cell['percent'] !== null) {
                    $percents[] = $cell['percent'];
                }
                if ($cell['passed'] === true) {
                    $passed++;
                }
            }

            $average = $percents === [] ? null : round(array_sum($percents) / count($percents), 2);

            $rows[] = [
                'user_id' => $userId,
                'cells' => $cells,
                'summary' => [
                    'total_columns' => count($cells),
                    'missing_count' => $missing,
                    'passed_count' => $passed,
                    'average_percent' => $average,
                ],
            ];
        }

        return $rows;
    }

    /**
     * @param  array<string, mixed>  $col
     * @return array<string, mixed>
     */
    private function assignmentCell(array $col, ?AssignmentSubmission $submission): array
    {
        if ($submission === null) {
            return [
                'type' => 'assignment', 'ref' => $col['public_id'], 'title' => $col['title'],
                'status' => null, 'score' => null, 'max' => $col['max_grade'], 'percent' => null,
                'passed' => null, 'is_late' => false, 'released' => false, 'missing' => true,
            ];
        }

        /** @var SubmissionGrade|null $grade */
        $grade = $submission->grade;
        $released = $grade?->isReleased() ?? false;
        $score = $grade?->score === null ? null : (float) $grade->score;
        $percent = ($score !== null && $col['max_grade'] > 0)
            ? round($score / (float) $col['max_grade'] * 100, 2) : null;

        return [
            'type' => 'assignment', 'ref' => $col['public_id'], 'title' => $col['title'],
            'status' => $submission->status->value,
            'score' => $score, 'max' => $col['max_grade'], 'percent' => $percent,
            'passed' => $grade?->passed,
            'is_late' => (bool) $submission->is_late,
            'released' => $released,
            'missing' => false,
        ];
    }

    /**
     * @param  array<string, mixed>  $col
     * @return array<string, mixed>
     */
    private function quizCell(array $col, ?AssessmentAttempt $attempt): array
    {
        if ($attempt === null) {
            return [
                'type' => 'quiz', 'ref' => $col['public_id'], 'title' => $col['title'],
                'status' => null, 'score' => null, 'percent' => null,
                'passed' => null, 'is_late' => false, 'released' => true, 'missing' => true,
            ];
        }

        return [
            'type' => 'quiz', 'ref' => $col['public_id'], 'title' => $col['title'],
            'status' => $attempt->status->value,
            'score' => $attempt->score === null ? null : (float) $attempt->score,
            'percent' => $attempt->percentage === null ? null : (float) $attempt->percentage,
            'passed' => $attempt->passed,
            'is_late' => false,
            'released' => true,
            'missing' => false,
        ];
    }

    /**
     * The learner roster: enrolled learners UNION anyone who already has work recorded, so nobody
     * with a submission is dropped even if the enrollment port is incomplete.
     *
     * @param  array{assignments: list<array<string, mixed>>, quizzes: list<array<string, mixed>>}  $columns
     * @return list<int>
     */
    private function roster(int $courseId, array $columns): array
    {
        $enrolled = $this->enrollment->enrolledLearnerIds($courseId);

        $assignmentIds = array_map(fn ($a) => $a['id'], $columns['assignments']);
        $quizIds = array_map(fn ($q) => $q['id'], $columns['quizzes']);

        $fromSubs = $assignmentIds === [] ? [] : AssignmentSubmission::query()
            ->whereIn('assignment_id', $assignmentIds)
            ->distinct()->pluck('user_id')->all();

        $fromAttempts = $quizIds === [] ? [] : AssessmentAttempt::query()
            ->whereIn('assessment_id', $quizIds)
            ->distinct()->pluck('user_id')->all();

        $ids = array_values(array_unique(array_map('intval', array_merge($enrolled, $fromSubs, $fromAttempts))));
        sort($ids);

        return $ids;
    }

    /**
     * @param  array{assignments: list<array<string, mixed>>, quizzes: list<array<string, mixed>>}  $columns
     * @param  array<string, mixed>  $filters
     * @return list<int>
     */
    private function rosterFiltered(int $courseId, array $columns, array $filters): array
    {
        $ids = $this->roster($courseId, $columns);
        $only = $filters['only'] ?? null;

        if ($only !== 'missing' && $only !== 'late') {
            return $ids;
        }

        // Row-level filter: keep learners with at least one missing / late cell.
        $rows = $this->rowsFor($ids, $columns);
        $kept = [];
        foreach ($rows as $row) {
            $match = $only === 'missing'
                ? $row['summary']['missing_count'] > 0
                : (bool) array_filter($row['cells'], fn ($c) => $c['is_late'] === true);
            if ($match) {
                $kept[] = $row['user_id'];
            }
        }

        return $kept;
    }

    /** @param array<int, string> $fields */
    private function csvLine(array $fields): string
    {
        return implode(',', array_map(fn (string $f): string => $this->csvEscape($f), $fields));
    }

    /** @param array<string, mixed> $cell */
    private function csvCell(array $cell): string
    {
        if ($cell['missing']) {
            return 'missing';
        }
        $score = $cell['score'];
        $late = $cell['is_late'] ? ' (late)' : '';

        return ($score === null ? (string) $cell['status'] : (string) $score).$late;
    }

    private function csvEscape(string $value): string
    {
        if (preg_match('/[",\n]/', $value) === 1) {
            return '"'.str_replace('"', '""', $value).'"';
        }

        return $value;
    }
}
