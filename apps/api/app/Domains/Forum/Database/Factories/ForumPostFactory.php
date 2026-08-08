<?php

declare(strict_types=1);

namespace App\Domains\Forum\Database\Factories;

use App\Domains\Forum\Models\ForumPost;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ForumPost>
 *
 * `thread_id` / `user_id` carry real FKs; tests MUST pass them explicitly
 * (['thread_id' => $thread->id, 'user_id' => $user->id]).
 */
class ForumPostFactory extends Factory
{
    protected $model = ForumPost::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'thread_id' => 1,
            'user_id' => 1,
            'parent_post_id' => null,
            'body' => '<p>'.fake()->sentence().'</p>',
            'is_instructor' => false,
        ];
    }

    public function instructor(): static
    {
        return $this->state(fn (): array => ['is_instructor' => true]);
    }
}
