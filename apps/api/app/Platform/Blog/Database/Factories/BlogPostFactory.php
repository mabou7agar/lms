<?php

namespace App\Platform\Blog\Database\Factories;

use App\Platform\Blog\Enums\PostStatus;
use App\Platform\Blog\Models\BlogPost;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BlogPost>
 */
class BlogPostFactory extends Factory
{
    protected $model = BlogPost::class;

    public function definition(): array
    {
        $title = ucfirst($this->faker->unique()->words(3, true));

        return [
            'slug' => $this->faker->unique()->slug(3),
            'title' => ['en' => $title, 'ar' => $title],
            'excerpt' => ['en' => $this->faker->sentence(), 'ar' => $this->faker->sentence()],
            'body' => [
                'en' => '<p>'.$this->faker->paragraph().'</p>',
                'ar' => '<p>'.$this->faker->paragraph().'</p>',
            ],
            'cover_image' => null,
            'blog_category_id' => null,
            'status' => PostStatus::Draft,
            'published_at' => null,
            'unpublished_at' => null,
            'is_featured' => false,
            'reading_minutes' => $this->faker->numberBetween(2, 12),
            'seo' => null,
            'author_id' => null,
            'reviewer_id' => null,
        ];
    }

    /** A live, published post (published_at in the past). */
    public function published(): static
    {
        return $this->state(fn () => [
            'status' => PostStatus::Published,
            'published_at' => now()->subDay(),
            'unpublished_at' => null,
        ]);
    }

    public function draft(): static
    {
        return $this->state(fn () => ['status' => PostStatus::Draft, 'published_at' => null]);
    }

    /** Published status but scheduled to go live in the future — must NOT be served yet. */
    public function scheduledFuture(): static
    {
        return $this->state(fn () => [
            'status' => PostStatus::Published,
            'published_at' => now()->addWeek(),
            'unpublished_at' => null,
        ]);
    }

    /** Published in the past but already unpublished — must NOT be served anymore. */
    public function expired(): static
    {
        return $this->state(fn () => [
            'status' => PostStatus::Published,
            'published_at' => now()->subWeek(),
            'unpublished_at' => now()->subDay(),
        ]);
    }

    public function featured(): static
    {
        return $this->state(fn () => ['is_featured' => true]);
    }

    public function slug(string $slug): static
    {
        return $this->state(fn () => ['slug' => $slug]);
    }
}
