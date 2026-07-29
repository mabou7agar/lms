<?php

namespace App\Domains\Assessment\Database\Factories;

use App\Domains\Assessment\Enums\AssignmentState;
use App\Domains\Assessment\Enums\LatePolicy;
use App\Domains\Assessment\Enums\SubmissionType;
use App\Domains\Assessment\Models\Assignment;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Assignment>
 *
 * course_id defaults to a fake scalar id: this context may not import Catalog's Course model, so
 * tests either accept the placeholder or pass ['course_id' => $course->id] explicitly.
 */
class AssignmentFactory extends Factory
{
    protected $model = Assignment::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'course_id' => fake()->numberBetween(1, 100000),
            'lesson_id' => null,
            'title' => rtrim(fake()->sentence(3), '.'),
            'instructions' => null,
            'submission_type' => SubmissionType::File->value,
            'allowed_file_types' => ['pdf', 'docx'],
            'max_file_size' => 10 * 1024 * 1024,
            'max_files' => 3,
            'attempt_limit' => null,
            'due_at' => null,
            'late_policy' => LatePolicy::Allowed->value,
            'late_penalty_percent' => null,
            'max_grade' => 100,
            'passing_grade' => 50,
            'rubric_id' => null,
            'publish_state' => AssignmentState::Draft->value,
            'required_for_completion' => false,
            'created_by' => null,
        ];
    }

    public function published(): static
    {
        return $this->state(fn () => ['publish_state' => AssignmentState::Published->value]);
    }

    public function requiredForCompletion(): static
    {
        return $this->state(fn () => ['required_for_completion' => true]);
    }

    public function textType(): static
    {
        return $this->state(fn () => ['submission_type' => SubmissionType::Text->value]);
    }

    public function dueAt(\DateTimeInterface $due, string $policy = LatePolicy::Allowed->value): static
    {
        return $this->state(fn () => ['due_at' => $due, 'late_policy' => $policy]);
    }
}
