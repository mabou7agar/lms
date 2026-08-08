<?php

declare(strict_types=1);

namespace App\Domains\Qna\Database\Factories;

use App\Domains\Qna\Enums\QuestionStatus;
use App\Domains\Qna\Models\CourseQuestion;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CourseQuestion>
 *
 * `course_id` and `user_id` are intentionally absent: this context may not import Catalog's Course
 * or Identity's User model (Deptrac), so tests supply them explicitly —
 * CourseQuestion::factory()->create(['course_id' => $course->id, 'user_id' => $user->id]).
 * organization_id is a server-side stamp, never set by the factory.
 */
class CourseQuestionFactory extends Factory
{
    protected $model = CourseQuestion::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'lesson_id' => null,
            'title' => rtrim(fake()->sentence(5), '.'),
            'body' => '<p>'.fake()->paragraph().'</p>',
            'lesson_timestamp_seconds' => null,
            'status' => QuestionStatus::Open->value,
            'pinned_at' => null,
            'accepted_answer_id' => null,
            'answers_count' => 0,
        ];
    }

    public function pinned(): static
    {
        return $this->state(fn () => ['pinned_at' => now()]);
    }

    public function resolved(): static
    {
        return $this->state(fn () => ['status' => QuestionStatus::Resolved->value]);
    }

    public function hidden(): static
    {
        return $this->state(fn () => ['status' => QuestionStatus::Hidden->value]);
    }
}
