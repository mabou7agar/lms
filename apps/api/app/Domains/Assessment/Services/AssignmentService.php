<?php

namespace App\Domains\Assessment\Services;

use App\Domains\Assessment\Enums\AssignmentState;
use App\Domains\Assessment\Events\AssignmentPublished;
use App\Domains\Assessment\Models\Assignment;
use App\Domains\Assessment\Models\AssignmentRubric;
use App\Domains\Assessment\Models\RubricCriterion;
use App\Domains\Assessment\Models\RubricLevel;
use App\Platform\Shared\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;

/**
 * Authoring service for assignments and their rubrics. All multi-row writes run in a transaction.
 * Server-controlled fields (course_id, publish_state, rubric_id, created_by) are set here, never
 * mass-assigned from request input.
 */
class AssignmentService
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @param  array<string, mixed>  $data  already validated request payload
     */
    public function createAssignment(int $courseId, int $actorId, array $data): Assignment
    {
        $assignment = new Assignment;
        $assignment->forceFill($this->attributes($data) + [
            'course_id' => $courseId,
            'publish_state' => AssignmentState::Draft->value,
            'created_by' => $actorId,
        ]);
        $assignment->save();

        $this->audit->log('assignment.created', $assignment, ['course_id' => $courseId], $actorId);

        return $assignment->refresh();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function updateAssignment(Assignment $assignment, array $data): Assignment
    {
        $assignment->forceFill($this->attributes($data))->save();

        return $assignment->refresh();
    }

    public function deleteAssignment(Assignment $assignment): void
    {
        $assignment->delete();
    }

    public function publish(Assignment $assignment): Assignment
    {
        if (! $assignment->isPublished()) {
            $assignment->forceFill(['publish_state' => AssignmentState::Published->value])->save();

            $this->audit->log('assignment.published', $assignment);

            AssignmentPublished::dispatch(
                (int) $assignment->id,
                (int) $assignment->course_id,
                $assignment->lesson_id === null ? null : (int) $assignment->lesson_id,
                (bool) $assignment->required_for_completion,
            );
        }

        return $assignment;
    }

    public function unpublish(Assignment $assignment): Assignment
    {
        $assignment->forceFill(['publish_state' => AssignmentState::Unpublished->value])->save();

        $this->audit->log('assignment.unpublished', $assignment);

        return $assignment;
    }

    /**
     * Build (replace) the assignment's rubric with deterministic point totals.
     *
     * A criterion's max_points is the highest points among its levels; the rubric total is the sum
     * of those maxima. Both are computed here, never trusted from the client, so the totals can
     * never drift from the levels.
     *
     * @param  array<string, mixed>  $data  ['title'=>?, 'criteria'=>[['title'=>,'description'=>?,'levels'=>[['title'=>,'points'=>,'description'=>?],...]],...]]
     */
    public function buildRubric(Assignment $assignment, array $data): AssignmentRubric
    {
        /** @var array<int, array<string, mixed>> $criteriaInput */
        $criteriaInput = is_array($data['criteria'] ?? null) ? $data['criteria'] : [];

        return DB::transaction(function () use ($assignment, $data, $criteriaInput): AssignmentRubric {
            // Replacing the rubric: drop the old one (cascade removes its criteria/levels).
            if ($assignment->rubric_id !== null) {
                AssignmentRubric::query()->where('id', $assignment->rubric_id)->delete();
            }

            $rubric = new AssignmentRubric;
            $rubric->forceFill([
                'assignment_id' => $assignment->id,
                'title' => is_string($data['title'] ?? null) ? $data['title'] : null,
                'total_points' => 0,
            ]);
            $rubric->save();

            $total = 0.0;

            foreach (array_values($criteriaInput) as $cPos => $criterionData) {
                /** @var array<int, array<string, mixed>> $levelsInput */
                $levelsInput = is_array($criterionData['levels'] ?? null) ? $criterionData['levels'] : [];

                $maxPoints = 0.0;
                foreach ($levelsInput as $levelData) {
                    $maxPoints = max($maxPoints, (float) ($levelData['points'] ?? 0));
                }

                $criterion = new RubricCriterion;
                $criterion->forceFill([
                    'rubric_id' => $rubric->id,
                    'title' => (string) ($criterionData['title'] ?? ''),
                    'description' => $criterionData['description'] ?? null,
                    'position' => $cPos,
                    'max_points' => $maxPoints,
                ]);
                $criterion->save();

                foreach (array_values($levelsInput) as $lPos => $levelData) {
                    $level = new RubricLevel;
                    $level->forceFill([
                        'criterion_id' => $criterion->id,
                        'title' => (string) ($levelData['title'] ?? ''),
                        'description' => $levelData['description'] ?? null,
                        'points' => (float) ($levelData['points'] ?? 0),
                        'position' => $lPos,
                    ]);
                    $level->save();
                }

                $total += $maxPoints;
            }

            $rubric->forceFill(['total_points' => $total])->save();

            // Point the assignment at its new active rubric.
            $assignment->forceFill(['rubric_id' => $rubric->id])->save();

            return $rubric->load('criteria.levels');
        });
    }

    /**
     * Whitelist of learner-settable attributes. Note the ABSENCE of course_id, publish_state,
     * rubric_id, created_by — those are controlled by the service, not the request.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function attributes(array $data): array
    {
        $allowed = [
            'title', 'instructions', 'submission_type', 'allowed_file_types', 'max_file_size',
            'max_files', 'attempt_limit', 'due_at', 'late_policy', 'late_penalty_percent',
            'max_grade', 'passing_grade', 'lesson_id', 'required_for_completion',
        ];

        return array_intersect_key($data, array_flip($allowed));
    }
}
