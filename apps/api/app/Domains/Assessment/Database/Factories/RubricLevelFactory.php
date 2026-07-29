<?php

namespace App\Domains\Assessment\Database\Factories;

use App\Domains\Assessment\Models\RubricCriterion;
use App\Domains\Assessment\Models\RubricLevel;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<RubricLevel> */
class RubricLevelFactory extends Factory
{
    protected $model = RubricLevel::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'criterion_id' => RubricCriterion::factory(),
            'title' => fake()->word(),
            'description' => null,
            'points' => fake()->numberBetween(0, 4),
            'position' => 0,
        ];
    }
}
