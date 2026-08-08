<?php

namespace App\Domains\Reviews\Database\Factories;

use App\Domains\Reviews\Enums\ReviewStatus;
use App\Domains\Reviews\Models\CourseReview;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CourseReview>
 *
 * course_id and user_id are genuine FKs, so a caller MUST pass real ids (no Catalog/Identity model
 * import here): CourseReview::factory()->create(['course_id' => $course->id, 'user_id' => $user->id]).
 * The placeholder scalars keep the definition self-contained for signature/shape purposes.
 */
class CourseReviewFactory extends Factory
{
    protected $model = CourseReview::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'course_id' => fake()->numberBetween(1, 100000),
            'user_id' => fake()->numberBetween(1, 100000),
            'rating' => fake()->numberBetween(1, 5),
            'body' => fake()->optional()->paragraph(),
            'status' => ReviewStatus::Published->value,
            'verified' => true,
            'helpful_count' => 0,
        ];
    }

    public function rating(int $stars): static
    {
        return $this->state(fn (): array => ['rating' => $stars]);
    }

    public function hidden(): static
    {
        return $this->state(fn (): array => ['status' => ReviewStatus::Hidden->value]);
    }

    public function unverified(): static
    {
        return $this->state(fn (): array => ['verified' => false]);
    }
}
