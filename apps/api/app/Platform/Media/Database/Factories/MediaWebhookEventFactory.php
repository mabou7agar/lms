<?php

namespace App\Platform\Media\Database\Factories;

use App\Platform\Media\Models\MediaWebhookEvent;
use App\Platform\Shared\Media\Enums\MediaProvider;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<MediaWebhookEvent>
 */
class MediaWebhookEventFactory extends Factory
{
    protected $model = MediaWebhookEvent::class;

    public function definition(): array
    {
        return [
            'provider' => MediaProvider::Fake->value,
            'provider_event_id' => 'evt_'.Str::random(20),
            'media_asset_id' => null,
            'type' => 'video.asset.ready',
            'received_at' => now(),
            'processed_at' => null,
        ];
    }
}
