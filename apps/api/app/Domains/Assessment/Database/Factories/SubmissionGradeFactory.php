<?php

namespace App\Domains\Assessment\Database\Factories;

use App\Domains\Assessment\Models\AssignmentSubmission;
use App\Domains\Assessment\Models\SubmissionGrade;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<SubmissionGrade> */
class SubmissionGradeFactory extends Factory
{
    protected $model = SubmissionGrade::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'submission_id' => AssignmentSubmission::factory(),
            'grader_id' => fake()->numberBetween(1, 100000),
            'score' => null,
            'passed' => null,
            'feedback' => null,
            'private_notes' => null,
            'rubric_result' => null,
            'released_at' => null,
            'version' => 1,
        ];
    }

    public function released(): static
    {
        return $this->state(fn () => ['released_at' => now()]);
    }
}
