<?php

declare(strict_types=1);

namespace App\Domains\Qna\Database\Factories;

use App\Domains\Qna\Models\CourseQuestion;
use App\Domains\Qna\Models\QuestionAnswer;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<QuestionAnswer>
 *
 * `user_id` is intentionally absent (no Identity import): tests supply it explicitly. `question_id`
 * defaults to a fresh question factory, which itself still needs a course_id/user_id when created
 * standalone — most tests pass an existing question.
 */
class QuestionAnswerFactory extends Factory
{
    protected $model = QuestionAnswer::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'question_id' => CourseQuestion::factory(),
            'body' => '<p>'.fake()->paragraph().'</p>',
            'is_instructor' => false,
            'accepted' => false,
        ];
    }

    public function fromInstructor(): static
    {
        return $this->state(fn () => ['is_instructor' => true]);
    }

    public function accepted(): static
    {
        return $this->state(fn () => ['accepted' => true]);
    }
}
