<?php

namespace App\Domains\Catalog\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * U8 - A single ordered image in a course's gallery. `media_public_id` is a cross-context reference to
 * a MediaAsset by its public_id (the value the MediaPicker stores), never an Eloquent FK — resolving
 * it to a signed URL is the Media platform's job. Deleting a gallery item removes only this ordering
 * row; the shared asset is never touched.
 *
 * @property int $id
 * @property int $course_id
 * @property string|null $media_public_id
 * @property int $position
 */
class CourseGalleryItem extends Model
{
    protected $table = 'course_gallery_items';

    protected $fillable = [
        'course_id', 'media_public_id', 'position',
    ];

    protected function casts(): array
    {
        return [
            'course_id' => 'integer',
            'position' => 'integer',
        ];
    }

    /** @return BelongsTo<Course, $this> */
    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class, 'course_id');
    }
}
