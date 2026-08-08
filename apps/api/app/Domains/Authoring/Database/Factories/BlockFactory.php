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
            'content_i18n' => null,
            'config' => null,
            'position' => 0,
            'publish_state' => PublishState::Draft->value,
            'lock_version' => 0,
            'created_by' => null,
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

    /** Seed the localized (en/ar) content surface directly. */
    public function withContent(array $contentI18n): static
    {
        return $this->state(fn () => ['content_i18n' => $contentI18n]);
    }
}
