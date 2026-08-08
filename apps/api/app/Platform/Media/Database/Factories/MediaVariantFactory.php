<?php

namespace App\Platform\Media\Database\Factories;

use App\Platform\Media\Models\MediaAsset;
use App\Platform\Media\Models\MediaVariant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<MediaVariant>
 *
 * A self-consistent derived variant row. Defaults to a webp "thumbnail" bound to a fresh image asset;
 * states cover the other keys/formats. Used by tests only — production rows are written exclusively by
 * ImageVariantService.
 */
class MediaVariantFactory extends Factory
{
    protected $model = MediaVariant::class;

    public function definition(): array
    {
        return [
            'media_asset_id' => MediaAsset::factory(),
            'variant_key' => 'thumbnail',
            'width' => 320,
            'height' => 180,
            'format' => 'webp',
            'storage_key' => 'media/variants/lesson_image/'.Str::uuid().'/thumbnail.webp',
            'size_bytes' => 4096,
        ];
    }

    public function key(string $key): self
    {
        return $this->state(fn () => ['variant_key' => $key]);
    }

    public function dimensions(int $width, int $height): self
    {
        return $this->state(fn () => ['width' => $width, 'height' => $height]);
    }

    public function format(string $format): self
    {
        return $this->state(fn () => ['format' => $format]);
    }
}
