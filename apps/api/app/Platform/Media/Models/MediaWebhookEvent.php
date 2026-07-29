<?php

namespace App\Platform\Media\Models;

use App\Platform\Media\Database\Factories\MediaWebhookEventFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * P2/W04 - Idempotency ledger row for a verified provider webhook. Keyed by the provider event id;
 * processed_at flips exactly once so replays are safe no-ops. No public id (never client-facing).
 *
 * @property int $id
 * @property string $provider
 * @property string $provider_event_id
 * @property int|null $media_asset_id
 * @property string $type
 * @property Carbon $received_at
 * @property Carbon|null $processed_at
 * @property-read MediaAsset|null $asset
 */
class MediaWebhookEvent extends Model
{
    /** @use HasFactory<MediaWebhookEventFactory> */
    use HasFactory;

    public $timestamps = false;

    protected $table = 'media_webhook_events';

    /** Written only through MediaIngestionService via firstOrCreate/forceFill(). */
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'media_asset_id' => 'integer',
            'received_at' => 'datetime',
            'processed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<MediaAsset, $this> */
    public function asset(): BelongsTo
    {
        return $this->belongsTo(MediaAsset::class, 'media_asset_id');
    }

    protected static function newFactory(): MediaWebhookEventFactory
    {
        return MediaWebhookEventFactory::new();
    }
}
