<?php

namespace App\Domains\Assessment\Support;

use App\Domains\Assessment\Models\AssignmentRubric;

/**
 * Builds the immutable rubric snapshot that is copied onto a submission at submit time. Once
 * written to `assignment_submissions.rubric_snapshot`, later edits to the live rubric never change
 * what historical work was graded against — grading reads the snapshot, never the live rubric.
 */
final class RubricSnapshot
{
    /**
     * @return array<string, mixed>|null null when the assignment has no rubric.
     */
    public static function forRubric(?AssignmentRubric $rubric): ?array
    {
        if ($rubric === null) {
            return null;
        }

        $rubric->load('criteria.levels');

        $criteria = $rubric->criteria
            ->map(fn ($criterion): array => [
                'public_id' => (string) $criterion->public_id,
                'title' => (string) $criterion->title,
                'description' => $criterion->description,
                'position' => (int) $criterion->position,
                'max_points' => (float) $criterion->max_points,
                'levels' => $criterion->levels
                    ->map(fn ($level): array => [
                        'public_id' => (string) $level->public_id,
                        'title' => (string) $level->title,
                        'description' => $level->description,
                        'points' => (float) $level->points,
                        'position' => (int) $level->position,
                    ])->values()->all(),
            ])->values()->all();

        return [
            'rubric_public_id' => (string) $rubric->public_id,
            'title' => $rubric->title,
            'total_points' => (float) $rubric->total_points,
            'criteria' => $criteria,
        ];
    }
}
