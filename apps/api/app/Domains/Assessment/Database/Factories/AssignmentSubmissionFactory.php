<?php

namespace App\Domains\Assessment\Database\Factories;

use App\Domains\Assessment\Enums\SubmissionStatus;
use App\Domains\Assessment\Models\Assignment;
use App\Domains\Assessment\Models\AssignmentSubmission;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AssignmentSubmission>
 *
 * user_id is intentionally required from the caller (no User import here):
 * AssignmentSubmission::factory()->create(['user_id' => $user->id]).
 */
class AssignmentSubmissionFactory extends Factory
{
    protected $model = AssignmentSubmission::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'assignment_id' => Assignment::factory(),
            'user_id' => fake()->numberBetween(1, 100000),
            'attempt_no' => 1,
            'status' => SubmissionStatus::Draft->value,
            'submitted_at' => null,
            'is_late' => false,
            'rubric_snapshot' => null,
            'text_response' => null,
            'external_url' => null,
        ];
    }

    public function submitted(): static
    {
        return $this->state(fn () => [
            'status' => SubmissionStatus::Submitted->value,
            'submitted_at' => now(),
        ]);
    }

    public function graded(): static
    {
        return $this->state(fn () => [
            'status' => SubmissionStatus::Graded->value,
            'submitted_at' => now(),
        ]);
    }
}
