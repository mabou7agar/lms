<?php

namespace App\Platform\Media\Database\Factories;

use App\Platform\Media\Enums\CaptionStatus;
use App\Platform\Media\Models\MediaAsset;
use App\Platform\Media\Models\MediaCaption;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<MediaCaption>
 */
class MediaCaptionFactory extends Factory
{
    protected $model = MediaCaption::class;

    public function definition(): array
    {
        return [
            'media_asset_id' => MediaAsset::factory(),
            'language' => 'en',
            'label' => 'English',
            'format' => 'vtt',
            'storage_key' => 'captions/'.Str::random(12).'.vtt',
            'provider_ref' => null,
            'status' => CaptionStatus::Ready->value,
            'created_by' => 1,
        ];
    }
}
