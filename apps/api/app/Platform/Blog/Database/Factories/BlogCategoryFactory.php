<?php

namespace App\Platform\Blog\Database\Factories;

use App\Platform\Blog\Models\BlogCategory;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BlogCategory>
 */
class BlogCategoryFactory extends Factory
{
    protected $model = BlogCategory::class;

    public function definition(): array
    {
        $name = ucfirst($this->faker->unique()->word());

        return [
            'slug' => $this->faker->unique()->slug(2),
            'name' => ['en' => $name, 'ar' => $name],
            'description' => ['en' => $this->faker->sentence(), 'ar' => $this->faker->sentence()],
            'position' => $this->faker->numberBetween(0, 100),
        ];
    }

    public function slug(string $slug): static
    {
        return $this->state(fn () => ['slug' => $slug]);
    }
}
