<?php

namespace App\Domains\Assessment\Services;

use App\Domains\Assessment\Enums\LatePolicy;
use App\Domains\Assessment\Enums\SubmissionStatus;
use App\Domains\Assessment\Events\AssignmentChangesRequested;
use App\Domains\Assessment\Events\AssignmentGraded;
use App\Domains\Assessment\Events\AssignmentGradeReleased;
use App\Domains\Assessment\Exceptions\GradeConflictException;
use App\Domains\Assessment\Exceptions\SubmissionNotAllowedException;
use App\Domains\Assessment\Models\Assignment;
use App\Domains\Assessment\Models\AssignmentSubmission;
use App\Domains\Assessment\Models\SubmissionGrade;
use App\Domains\Assessment\Models\SubmissionGradeEvent;
use App\Platform\Shared\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;

/**
 * Manual grading with optimistic concurrency.
 *
 * The single current grade lives in submission_grades and carries a `version`. A grader passes the
 * version they loaded; if another grader has written since, the write is a 409 conflict rather than
 * a silent overwrite. Every action appends an immutable submission_grade_events row (the history),
 * and score/feedback stay invisible to the learner until explicitly RELEASED.
 */
class GradingService
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * Record or re-record a grade. Score is taken from the rubric selection when one is supplied
     * (validated against the submission's IMMUTABLE snapshot), otherwise from a numeric score.
     *
     * @param  array<string, mixed>  $data  ['score'=>?, 'rubric_result'=>?[], 'feedback'=>?, 'private_notes'=>?, 'expected_version'=>?int]
     */
    public function grade(AssignmentSubmission $submission, int $graderId, array $data): SubmissionGrade
    {
        return DB::transaction(function () use ($submission, $graderId, $data): SubmissionGrade {
            $grade = SubmissionGrade::query()
                ->where('submission_id', $submission->id)
                ->lockForUpdate()
                ->first();

            $currentVersion = $grade->version ?? 0;
            $expected = $data['expected_version'] ?? null;

            // Optimistic concurrency: a stale version means someone graded in between.
            if ($expected !== null && (int) $expected !== $currentVersion) {
                throw new GradeConflictException('This submission was graded by someone else. Reload and try again.');
            }

            $assignment = $submission->assignment;
            $score = $this->resolveScore($submission, $data);
            $score = $this->applyLatePenalty($assignment, $submission, $score);

            $passed = $this->resolvePassed($assignment, $score);
            $isRegrade = $grade !== null;
            $newVersion = $currentVersion + 1;

            if ($grade === null) {
                $grade = new SubmissionGrade;
                $grade->forceFill(['submission_id' => $submission->id]);
            }

            $grade->forceFill([
                'grader_id' => $graderId,
                'score' => $score,
                'passed' => $passed,
                'feedback' => $data['feedback'] ?? $grade->feedback,
                'private_notes' => $data['private_notes'] ?? $grade->private_notes,
                'rubric_result' => is_array($data['rubric_result'] ?? null) ? $data['rubric_result'] : $grade->rubric_result,
                'version' => $newVersion,
            ])->save();

            // Grading does NOT reveal to the learner; it parks the attempt in review.
            $submission->forceFill(['status' => SubmissionStatus::UnderReview->value])->save();

            $this->recordEvent($submission, $graderId, $isRegrade ? 'regraded' : 'graded', $score, $passed, $newVersion);
            $this->audit->log($isRegrade ? 'assignment.regraded' : 'assignment.graded', $grade, [
                'submission_id' => $submission->id,
                'score' => $score,
            ], $graderId);

            AssignmentGraded::dispatch(
                (int) $submission->id,
                (int) $submission->assignment_id,
                (int) $submission->user_id,
                $graderId,
                $score,
                $passed,
                $newVersion,
            );

            return $grade->refresh();
        });
    }

    /** Ask the learner to revise. The attempt becomes resubmittable; no score is shown. */
    public function requestChanges(AssignmentSubmission $submission, int $graderId, ?string $note = null): AssignmentSubmission
    {
        return DB::transaction(function () use ($submission, $graderId, $note): AssignmentSubmission {
            $submission->forceFill(['status' => SubmissionStatus::ChangesRequested->value])->save();

            $grade = SubmissionGrade::query()->where('submission_id', $submission->id)->first();
            $this->recordEvent($submission, $graderId, 'changes_requested', null, null, (int) ($grade->version ?? 0), $note);
            $this->audit->log('assignment.changes_requested', $submission, ['note' => $note], $graderId);

            AssignmentChangesRequested::dispatch(
                (int) $submission->id,
                (int) $submission->assignment_id,
                (int) $submission->user_id,
                $graderId,
            );

            return $submission->refresh();
        });
    }

    /** Make the recorded grade visible to the learner and finalize the attempt. */
    public function release(AssignmentSubmission $submission, int $graderId): SubmissionGrade
    {
        return DB::transaction(function () use ($submission, $graderId): SubmissionGrade {
            $grade = SubmissionGrade::query()
                ->where('submission_id', $submission->id)
                ->lockForUpdate()
                ->first();

            if ($grade === null) {
                throw new SubmissionNotAllowedException('This submission has not been graded yet.');
            }

            if (! $grade->isReleased()) {
                $grade->forceFill(['released_at' => now()])->save();
            }

            $submission->forceFill(['status' => SubmissionStatus::Graded->value])->save();

            $this->recordEvent($submission, $graderId, 'released', $grade->score === null ? null : (float) $grade->score, $grade->passed, (int) $grade->version);
            $this->audit->log('assignment.grade_released', $grade, ['submission_id' => $submission->id], $graderId);

            $assignment = $submission->assignment;
            AssignmentGradeReleased::dispatch(
                (int) $submission->id,
                (int) $submission->assignment_id,
                (int) ($assignment->course_id ?? 0),
                $assignment?->lesson_id === null ? null : (int) $assignment->lesson_id,
                (int) $submission->user_id,
                $grade->passed,
            );

            return $grade->refresh();
        });
    }

    /** Withdraw a released grade (correction workflow). Returns the attempt to review. */
    public function unrelease(AssignmentSubmission $submission, int $graderId): SubmissionGrade
    {
        return DB::transaction(function () use ($submission, $graderId): SubmissionGrade {
            $grade = SubmissionGrade::query()
                ->where('submission_id', $submission->id)
                ->lockForUpdate()
                ->firstOrFail();

            $grade->forceFill(['released_at' => null])->save();
            $submission->forceFill(['status' => SubmissionStatus::UnderReview->value])->save();

            $this->recordEvent($submission, $graderId, 'unreleased', $grade->score === null ? null : (float) $grade->score, $grade->passed, (int) $grade->version);
            $this->audit->log('assignment.grade_unreleased', $grade, ['submission_id' => $submission->id], $graderId);

            return $grade->refresh();
        });
    }

    /**
     * Score from the rubric selection, validated against the snapshot. Falls back to a numeric
     * score. The rubric wins when present so a client cannot send a mismatched free number.
     *
     * @param  array<string, mixed>  $data
     */
    private function resolveScore(AssignmentSubmission $submission, array $data): ?float
    {
        $rubricResult = $data['rubric_result'] ?? null;
        $snapshot = $submission->rubric_snapshot;

        if (is_array($rubricResult) && is_array($snapshot)) {
            return $this->scoreFromRubric($snapshot, $rubricResult);
        }

        if (array_key_exists('score', $data) && $data['score'] !== null) {
            return (float) $data['score'];
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @param  array<int, array<string, mixed>>  $result
     */
    private function scoreFromRubric(array $snapshot, array $result): float
    {
        /** @var array<int, array<string, mixed>> $criteria */
        $criteria = is_array($snapshot['criteria'] ?? null) ? $snapshot['criteria'] : [];

        // Index snapshot levels by criterion/level public id so a selection can only award points
        // that actually exist in the frozen rubric.
        $levelPoints = [];
        foreach ($criteria as $criterion) {
            $cId = (string) ($criterion['public_id'] ?? '');
            foreach ((array) ($criterion['levels'] ?? []) as $level) {
                $levelPoints[$cId][(string) ($level['public_id'] ?? '')] = (float) ($level['points'] ?? 0);
            }
        }

        $total = 0.0;
        foreach ($result as $selection) {
            $cId = (string) ($selection['criterion_public_id'] ?? '');
            $lId = (string) ($selection['level_public_id'] ?? '');
            $total += $levelPoints[$cId][$lId] ?? 0.0;
        }

        return $total;
    }

    private function applyLatePenalty(?Assignment $assignment, AssignmentSubmission $submission, ?float $score): ?float
    {
        if ($score === null || $assignment === null || ! $submission->is_late) {
            return $score;
        }

        if ($assignment->late_policy !== LatePolicy::Penalised || $assignment->late_penalty_percent === null) {
            return $score;
        }

        $factor = max(0.0, 1 - ((int) $assignment->late_penalty_percent / 100));

        return round($score * $factor, 2);
    }

    private function resolvePassed(?Assignment $assignment, ?float $score): ?bool
    {
        if ($assignment === null || ! $assignment->hasPassMark() || $score === null) {
            return null;
        }

        return $score >= (float) $assignment->passing_grade;
    }

    private function recordEvent(AssignmentSubmission $submission, int $graderId, string $event, ?float $score, ?bool $passed, int $version, ?string $note = null): void
    {
        $row = new SubmissionGradeEvent;
        $row->forceFill([
            'submission_id' => $submission->id,
            'grader_id' => $graderId,
            'event' => $event,
            'score' => $score,
            'passed' => $passed,
            'version' => $version,
            'note' => $note,
            'created_at' => now(),
        ]);
        $row->save();
    }
}
