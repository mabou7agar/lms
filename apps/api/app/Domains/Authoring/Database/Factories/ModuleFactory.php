<?php

namespace App\Domains\Authoring\Database\Factories;

use App\Domains\Authoring\Enums\PublishState;
use App\Domains\Authoring\Models\Module;
use App\Domains\Catalog\Models\Course;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Module>
 */
class ModuleFactory extends Factory
{
    protected $model = Module::class;

    public function definition(): array
    {
        return [
            'course_id' => Course::factory(),
            'parent_id' => null,
            'title' => rtrim(fake()->sentence(3), '.'),
            'summary' => fake()->sentence(),
            'position' => 0,
            'publish_state' => PublishState::Draft->value,
        ];
    }

    public function published(): static
    {
        return $this->state(fn () => ['publish_state' => PublishState::Published->value]);
    }

    public function childOf(Module $parent): static
    {
        return $this->state(fn () => [
            'course_id' => $parent->course_id,
            'parent_id' => $parent->getKey(),
        ]);
    }
}
