<?php

namespace App\Domains\Catalog\Database\Factories;

use App\Domains\Catalog\Enums\CourseStatus;
use App\Domains\Catalog\Models\Course;
use App\Platform\Shared\Enums\Visibility;
use App\Platform\Shared\Helpers\Slug;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Course>
 */
class CourseFactory extends Factory
{
    protected $model = Course::class;

    public function definition(): array
    {
        $title = fake()->unique()->sentence(3);

        return [
            'title' => rtrim($title, '.'),
            'slug' => Slug::make($title).'-'.fake()->unique()->numberBetween(1, 999999),
            'subtitle' => fake()->sentence(),
            'description' => fake()->paragraph(),
            'status' => CourseStatus::Draft->value,
            'visibility' => Visibility::Public->value,
            'is_featured' => false,
            'position' => 0,
        ];
    }

    public function published(): static
    {
        return $this->state(fn () => [
            'status' => CourseStatus::Published->value,
            'published_at' => now(),
        ]);
    }

    public function archived(): static
    {
        return $this->state(fn () => ['status' => CourseStatus::Archived->value]);
    }

    public function review(): static
    {
        return $this->state(fn () => ['status' => CourseStatus::Review->value]);
    }

    public function approved(): static
    {
        return $this->state(fn () => ['status' => CourseStatus::Approved->value]);
    }

    public function unpublished(): static
    {
        return $this->state(fn () => ['status' => CourseStatus::Unpublished->value]);
    }

    /** A course scheduled to auto-publish at the given time (defaults to one hour out). */
    public function scheduled(?\DateTimeInterface $at = null): static
    {
        return $this->state(fn () => [
            'status' => CourseStatus::Scheduled->value,
            'scheduled_publish_at' => $at ?? now()->addHour(),
        ]);
    }

    public function featured(): static
    {
        return $this->state(fn () => ['is_featured' => true]);
    }

    public function hidden(): static
    {
        return $this->state(fn () => ['visibility' => Visibility::Private->value]);
    }
}
