<?php

namespace App\Platform\Media\Database\Factories;

use App\Platform\Media\Models\MediaAsset;
use App\Platform\Media\Models\MediaAttachment;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MediaAttachment>
 */
class MediaAttachmentFactory extends Factory
{
    protected $model = MediaAttachment::class;

    public function definition(): array
    {
        return [
            'media_asset_id' => MediaAsset::factory(),
            'attachable_type' => 'authoring.block',
            'attachable_id' => 1,
            'role' => 'attachment',
            'course_id' => null,
            'attached_by' => 1,
        ];
    }
}
