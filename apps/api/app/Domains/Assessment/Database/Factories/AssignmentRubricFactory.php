<?php

namespace App\Domains\Assessment\Database\Factories;

use App\Domains\Assessment\Models\Assignment;
use App\Domains\Assessment\Models\AssignmentRubric;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<AssignmentRubric> */
class AssignmentRubricFactory extends Factory
{
    protected $model = AssignmentRubric::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'assignment_id' => Assignment::factory(),
            'title' => 'Rubric',
            'total_points' => 0,
        ];
    }
}
