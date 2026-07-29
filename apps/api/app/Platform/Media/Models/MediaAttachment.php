<?php

namespace App\Platform\Media\Models;

use App\Platform\Media\Database\Factories\MediaAttachmentFactory;
use App\Platform\Shared\Traits\HasPublicId;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * P2/W04 - A usage record binding a media asset to another context's entity (a lesson block, a
 * submission, ...) by SCALAR polymorphic reference. Its existence is what blocks hard-deleting an
 * asset that is still in use.
 *
 * @property int $id
 * @property string $public_id
 * @property int $media_asset_id
 * @property string $attachable_type
 * @property int $attachable_id
 * @property string $role
 * @property int|null $course_id
 * @property int $attached_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read MediaAsset $asset
 */
class MediaAttachment extends Model
{
    /** @use HasFactory<MediaAttachmentFactory> */
    use HasFactory;

    use HasPublicId;

    protected $table = 'media_attachments';

    /** Written only through MediaAttachmentService via forceFill(). */
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'media_asset_id' => 'integer',
            'attachable_id' => 'integer',
            'course_id' => 'integer',
            'attached_by' => 'integer',
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
    public function scopeForAttachable(Builder $query, string $type, int $id): Builder
    {
        return $query->where('attachable_type', $type)->where('attachable_id', $id);
    }

    protected static function newFactory(): MediaAttachmentFactory
    {
        return MediaAttachmentFactory::new();
    }
}
