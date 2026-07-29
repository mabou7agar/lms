<?php

namespace App\Domains\Assessment\Http\Resources;

use App\Domains\Assessment\Models\AssignmentRubric;
use App\Domains\Assessment\Models\RubricCriterion;
use App\Domains\Assessment\Models\RubricLevel;
use App\Platform\Shared\Resources\BaseResource;
use Illuminate\Http\Request;

/**
 * A rubric with its criteria and levels. Safe for both instructor and learner views — it is the
 * grading standard, not anyone's score.
 *
 * @property AssignmentRubric $resource
 */
class RubricResource extends BaseResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->public_id,
            'title' => $this->resource->title,
            'total_points' => (float) $this->resource->total_points,
            'criteria' => $this->resource->criteria->map(fn (RubricCriterion $c): array => [
                'id' => $c->public_id,
                'title' => $c->title,
                'description' => $c->description,
                'position' => $c->position,
                'max_points' => (float) $c->max_points,
                'levels' => $c->levels->map(fn (RubricLevel $l): array => [
                    'id' => $l->public_id,
                    'title' => $l->title,
                    'description' => $l->description,
                    'points' => (float) $l->points,
                    'position' => $l->position,
                ])->values()->all(),
            ])->values()->all(),
        ];
    }
}
