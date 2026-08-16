<?php

declare(strict_types=1);

namespace App\Domains\Authoring\Models;

use App\Domains\Authoring\Enums\ResourceVisibility;
use App\Platform\Shared\Traits\HasPublicId;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * A downloadable file published against a course, or against one lesson within it.
 *
 * The row owns the PUBLICATION decision — title, ordering, who may have it, whether it may be
 * downloaded at all — while the bytes stay in the media library behind a signed, expiring URL. No
 * storage key or provider id is ever carried on this model's serialized form.
 *
 * @property int $id
 * @property string $public_id
 * @property int $course_id
 * @property int|null $lesson_id
 * @property int $media_asset_id
 * @property string $title
 * @property string|null $description
 * @property ResourceVisibility $visibility
 * @property bool $downloadable
 * @property int $position
 * @property string|null $mime_type
 * @property int|null $size_bytes
 * @property int|null $created_by
 * @property Carbon|null $created_at
 */
class CourseResource extends Model
{
    use HasPublicId;
    use SoftDeletes;

    protected $table = 'course_resources';

    protected $fillable = [
        'course_id', 'lesson_id', 'media_asset_id', 'title', 'description',
        'visibility', 'downloadable', 'position', 'created_by',
        'mime_type', 'size_bytes',
    ];

    /** The storage reference is never serialized; callers get a signed URL from the controller. */
    protected $hidden = ['media_asset_id'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'visibility' => ResourceVisibility::class,
            'downloadable' => 'boolean',
            'position' => 'integer',
            'lesson_id' => 'integer',
            'media_asset_id' => 'integer',
            'size_bytes' => 'integer',
        ];
    }

    /** Belongs to the whole course rather than to one lesson. */
    public function isCourseLevel(): bool
    {
        return $this->lesson_id === null;
    }

    /** A file anyone may take, because the course chose to give it away. */
    public function isPreview(): bool
    {
        return $this->visibility === ResourceVisibility::Preview;
    }

    /**
     * Course-level resources only, in the order the author set.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeCourseLevel(Builder $query): Builder
    {
        return $query->whereNull('lesson_id');
    }

    /**
     * What an unentitled visitor may see — the previews, and nothing else.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopePreviewable(Builder $query): Builder
    {
        return $query->where('visibility', ResourceVisibility::Preview->value);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('position')->orderBy('id');
    }
}
