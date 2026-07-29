<?php

namespace App\Platform\Media\Models;

use App\Platform\Media\Database\Factories\MediaCaptionFactory;
use App\Platform\Media\Enums\CaptionStatus;
use App\Platform\Shared\Traits\HasPublicId;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * P2/W04 - A caption/subtitle track (metadata only) for a media asset.
 *
 * @property int $id
 * @property string $public_id
 * @property int $media_asset_id
 * @property string $language
 * @property string $label
 * @property string $format
 * @property string|null $storage_key
 * @property string|null $provider_ref
 * @property CaptionStatus $status
 * @property int $created_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read MediaAsset $asset
 */
class MediaCaption extends Model
{
    /** @use HasFactory<MediaCaptionFactory> */
    use HasFactory;

    use HasPublicId;

    protected $table = 'media_captions';

    /** Written only through MediaCaptionService via forceFill(). */
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'media_asset_id' => 'integer',
            'status' => CaptionStatus::class,
            'created_by' => 'integer',
        ];
    }

    /** @return BelongsTo<MediaAsset, $this> */
    public function asset(): BelongsTo
    {
        return $this->belongsTo(MediaAsset::class, 'media_asset_id');
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeForAsset(Builder $query, int $assetId): Builder
    {
        return $query->where('media_asset_id', $assetId);
    }

    protected static function newFactory(): MediaCaptionFactory
    {
        return MediaCaptionFactory::new();
    }
}
