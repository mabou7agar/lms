<?php

namespace App\Domains\Reviews\Models;

use App\Domains\Reviews\Database\Factories\CourseReviewFactory;
use App\Domains\Reviews\Enums\ReviewStatus;
use App\Domains\Reviews\Tenancy\InheritsCourseTenancy;
use App\Platform\Shared\Moderation\Concerns\CanBeReported;
use App\Platform\Shared\Traits\HasPublicId;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A learner's rating + written review of a course. Course is referenced by scalar course_id only
 * (no Catalog model relation — Deptrac). Tenancy is inherited transitively from the owning course
 * via InheritsCourseTenancy (join to `courses` by string); the local `organization_id` is a
 * denormalized stamp for reporting and is intentionally NOT fillable, so a forged organization_id in
 * a request payload can never be mass-assigned. Reviews are reportable into the shared moderation
 * queue via CanBeReported.
 *
 * @property int $id
 * @property string $public_id
 * @property int $course_id
 * @property int $user_id
 * @property int|null $enrollment_id
 * @property int|null $organization_id
 * @property int $rating
 * @property string|null $body
 * @property ReviewStatus $status
 * @property bool $verified
 * @property string|null $instructor_response
 * @property int|null $responded_by
 * @property \Illuminate\Support\Carbon|null $responded_at
 * @property int $helpful_count
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class CourseReview extends Model
{
    use CanBeReported;

    /** @use HasFactory<CourseReviewFactory> */
    use HasFactory;

    use HasPublicId;
    use InheritsCourseTenancy;
    use SoftDeletes;

    /**
     * organization_id is deliberately excluded — it is stamped server-side from the course, never
     * mass-assigned. Client payloads only ever carry rating + body; every other attribute is set by
     * the domain actions.
     */
    protected $fillable = [
        'course_id',
        'user_id',
        'enrollment_id',
        'rating',
        'body',
        'status',
        'verified',
        'instructor_response',
        'responded_by',
        'responded_at',
        'helpful_count',
    ];

    protected function casts(): array
    {
        return [
            'course_id' => 'integer',
            'user_id' => 'integer',
            'enrollment_id' => 'integer',
            'organization_id' => 'integer',
            'rating' => 'integer',
            'status' => ReviewStatus::class,
            'verified' => 'boolean',
            'responded_by' => 'integer',
            'responded_at' => 'datetime',
            'helpful_count' => 'integer',
        ];
    }

    /** @return HasMany<CourseReviewVote, $this> */
    public function votes(): HasMany
    {
        return $this->hasMany(CourseReviewVote::class, 'review_id');
    }

    /**
     * @param  Builder<CourseReview>  $query
     * @return Builder<CourseReview>
     */
    public function scopePublished(Builder $query): Builder
    {
        return $query->where('status', ReviewStatus::Published->value);
    }

    public function isOwnedBy(int $userId): bool
    {
        return (int) $this->getAttribute('user_id') === $userId;
    }

    protected static function newFactory(): CourseReviewFactory
    {
        return CourseReviewFactory::new();
    }
}
