<?php

namespace App\Platform\Media\Models;

use App\Platform\Media\Database\Factories\MediaFolderFactory;
use App\Platform\Shared\Traits\HasPublicId;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Phase 8 / D1 - An optional organizational folder/collection for the DAM. Purely for grouping: it
 * owns no engine state, so (unlike MediaAsset) it is a conventional, mass-assignable Filament-managed
 * entity. Deleting a folder never deletes its assets — the folder service reassigns them to root
 * (folder_id = null) first, and the schema's nullOnDelete FKs are the durable backstop.
 *
 * @property int $id
 * @property string $public_id
 * @property string $name
 * @property int|null $parent_id
 * @property int $created_by
 * @property int|null $owner_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read MediaFolder|null $parent
 * @property-read Collection<int, MediaFolder> $children
 * @property-read Collection<int, MediaAsset> $assets
 */
class MediaFolder extends Model
{
    /** @use HasFactory<MediaFolderFactory> */
    use HasFactory;

    use HasPublicId;

    protected $table = 'media_folders';

    /** Organizational only — safe to mass-assign these; created_by is set by the resource/service. */
    protected $fillable = ['name', 'parent_id', 'owner_id', 'created_by'];

    protected function casts(): array
    {
        return [
            'parent_id' => 'integer',
            'created_by' => 'integer',
            'owner_id' => 'integer',
        ];
    }

    /** @return BelongsTo<MediaFolder, $this> */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /** @return HasMany<MediaFolder, $this> */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    /** @return HasMany<MediaAsset, $this> */
    public function assets(): HasMany
    {
        return $this->hasMany(MediaAsset::class, 'folder_id');
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeOwnedBy(Builder $query, int $actorId): Builder
    {
        return $query->where('created_by', $actorId);
    }

    protected static function newFactory(): MediaFolderFactory
    {
        return MediaFolderFactory::new();
    }
}
