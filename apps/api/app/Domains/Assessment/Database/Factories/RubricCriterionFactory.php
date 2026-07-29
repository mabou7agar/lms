<?php

namespace App\Domains\Assessment\Database\Factories;

use App\Domains\Assessment\Models\AssignmentRubric;
use App\Domains\Assessment\Models\RubricCriterion;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<RubricCriterion> */
class RubricCriterionFactory extends Factory
{
    protected $model = RubricCriterion::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'rubric_id' => AssignmentRubric::factory(),
            'title' => rtrim(fake()->sentence(2), '.'),
            'description' => null,
            'position' => 0,
            'max_points' => 0,
        ];
    }
}
