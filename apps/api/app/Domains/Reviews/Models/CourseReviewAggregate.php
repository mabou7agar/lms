<?php

namespace App\Domains\Reviews\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Denormalized per-course rating summary (count, sum, average, 1..5 distribution). Kept in its OWN
 * table so the Catalog Course model is never touched. Always recomputed from source reviews by
 * ReviewAggregateService — this row is a cache, not a ledger. course_id is the primary key.
 *
 * @property int $course_id
 * @property int $reviews_count
 * @property int $ratings_sum
 * @property float $avg_rating
 * @property int $dist_1
 * @property int $dist_2
 * @property int $dist_3
 * @property int $dist_4
 * @property int $dist_5
 */
class CourseReviewAggregate extends Model
{
    protected $table = 'course_review_aggregates';

    protected $primaryKey = 'course_id';

    public $incrementing = false;

    protected $keyType = 'int';

    /** Only updated_at is tracked (see migration); Eloquent's created_at is disabled below. */
    public const CREATED_AT = null;

    protected $fillable = [
        'course_id',
        'reviews_count',
        'ratings_sum',
        'avg_rating',
        'dist_1',
        'dist_2',
        'dist_3',
        'dist_4',
        'dist_5',
        'updated_at',
    ];

    protected function casts(): array
    {
        return [
            'course_id' => 'integer',
            'reviews_count' => 'integer',
            'ratings_sum' => 'integer',
            'avg_rating' => 'float',
            'dist_1' => 'integer',
            'dist_2' => 'integer',
            'dist_3' => 'integer',
            'dist_4' => 'integer',
            'dist_5' => 'integer',
            'updated_at' => 'datetime',
        ];
    }

    /** An empty aggregate placeholder for a course with no reviews yet (never persisted here). */
    public static function empty(int $courseId): self
    {
        $aggregate = new self;
        $aggregate->forceFill([
            'course_id' => $courseId,
            'reviews_count' => 0,
            'ratings_sum' => 0,
            'avg_rating' => 0.0,
            'dist_1' => 0,
            'dist_2' => 0,
            'dist_3' => 0,
            'dist_4' => 0,
            'dist_5' => 0,
        ]);

        return $aggregate;
    }
}
