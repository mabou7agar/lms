<?php

namespace App\Domains\Authoring\Database\Factories;

use App\Domains\Authoring\Enums\BlockType;
use App\Domains\Authoring\Enums\PublishState;
use App\Domains\Authoring\Models\Block;
use App\Domains\Authoring\Models\Lesson;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Block>
 */
class BlockFactory extends Factory
{
    protected $model = Block::class;

    public function definition(): array
    {
        // `family` is intentionally omitted — the Block model derives it from `type` on save.
        return [
            'lesson_id' => Lesson::factory(),
            'type' => BlockType::Article->value,
            'payload' => [],
            'position' => 0,
            'publish_state' => PublishState::Draft->value,
        ];
    }

    public function published(): static
    {
        return $this->state(fn () => ['publish_state' => PublishState::Published->value]);
    }

    public function ofType(BlockType $type): static
    {
        return $this->state(fn () => ['type' => $type->value]);
    }
}
