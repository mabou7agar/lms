<?php

namespace App\Platform\Media\Models;

use App\Platform\Media\Database\Factories\MediaVariantFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Phase A / D6 - A single derived image variant of a MediaAsset (e.g. thumbnail / small / medium /
 * large / a webp twin). Immutable: rows are only inserted or upserted by ImageVariantService via
 * forceFill() — there is no update path, so the model pins UPDATED_AT = null. A variant's storage_key
 * is ALWAYS a new object; it never equals the parent asset's original key.
 *
 * @property int $id
 * @property int $media_asset_id
 * @property string $variant_key
 * @property int $width
 * @property int $height
 * @property string $format
 * @property string $storage_key
 * @property int $size_bytes
 * @property Carbon|null $created_at
 * @property-read MediaAsset $asset
 */
class MediaVariant extends Model
{
    /** @use HasFactory<MediaVariantFactory> */
    use HasFactory;

    /** Immutable derivation — no updated_at column. */
    public const UPDATED_AT = null;

    protected $table = 'media_variants';

    /** Written only through ImageVariantService via forceFill(). */
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'media_asset_id' => 'integer',
            'width' => 'integer',
            'height' => 'integer',
            'size_bytes' => 'integer',
            'created_at' => 'datetime',
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

    protected static function newFactory(): MediaVariantFactory
    {
        return MediaVariantFactory::new();
    }
}
