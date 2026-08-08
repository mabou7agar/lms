<?php

declare(strict_types=1);

namespace App\Domains\Forum\Database\Factories;

use App\Domains\Forum\Models\ForumThread;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ForumThread>
 *
 * `course_id` / `user_id` default to placeholder scalars: this context may not import the Course or
 * User model, and both columns carry a real FK, so tests MUST pass
 * ['course_id' => $course->id, 'user_id' => $user->id] explicitly.
 */
class ForumThreadFactory extends Factory
{
    protected $model = ForumThread::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'course_id' => 1,
            'user_id' => 1,
            'organization_id' => null,
            'title' => rtrim(fake()->sentence(4), '.'),
            'body' => '<p>'.fake()->paragraph().'</p>',
            'last_post_at' => now(),
        ];
    }

    public function pinned(): static
    {
        return $this->state(fn (): array => ['pinned_at' => now()]);
    }

    public function locked(): static
    {
        return $this->state(fn (): array => ['locked_at' => now()]);
    }
}
