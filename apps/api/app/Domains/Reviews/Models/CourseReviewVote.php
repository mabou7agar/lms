<?php

namespace App\Domains\Reviews\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A single "helpful" vote by a learner on a review. The unique (review_id, user_id) index makes a
 * vote at most one per learner; helpful_count on the review is always recomputed from these rows, so
 * voting is idempotent.
 *
 * @property int $id
 * @property int $review_id
 * @property int $user_id
 */
class CourseReviewVote extends Model
{
    protected $fillable = ['review_id', 'user_id'];

    protected function casts(): array
    {
        return [
            'review_id' => 'integer',
            'user_id' => 'integer',
        ];
    }

    /** @return BelongsTo<CourseReview, $this> */
    public function review(): BelongsTo
    {
        return $this->belongsTo(CourseReview::class, 'review_id');
    }
}
