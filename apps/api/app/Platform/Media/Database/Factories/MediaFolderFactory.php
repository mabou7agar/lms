<?php

namespace App\Platform\Media\Database\Factories;

use App\Platform\Media\Models\MediaFolder;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MediaFolder>
 */
class MediaFolderFactory extends Factory
{
    protected $model = MediaFolder::class;

    public function definition(): array
    {
        return [
            'name' => 'Folder '.$this->faker->unique()->numberBetween(1, 100000),
            'parent_id' => null,
            'created_by' => 1,
            'owner_id' => null,
        ];
    }

    public function ownedBy(int $actorId): self
    {
        return $this->state(fn () => ['created_by' => $actorId]);
    }

    public function childOf(MediaFolder $parent): self
    {
        return $this->state(fn () => ['parent_id' => $parent->id]);
    }
}
