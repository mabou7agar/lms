<?php

namespace App\Contexts\Learning\Models;

use App\Contexts\Learning\Database\Factories\EnrollmentFactory;
use App\Contexts\Learning\Enums\EnrollmentSource;
use App\Contexts\Learning\Enums\EnrollmentStatus;
use App\Domains\Catalog\Models\Course;
use App\Platform\Shared\Traits\HasPublicId;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A learner's relationship with a course. Owns status and completion percentage. Entitlement
 * is granted by Learning actions (Commerce will call GrantEnrollmentAction later).
 */
class Enrollment extends Model
{
    /** @use HasFactory<EnrollmentFactory> */
    use HasFactory;

    use HasPublicId;
    use SoftDeletes;

    protected $fillable = [
        'user_id', 'course_id', 'status', 'source', 'progress_percentage', 'enrolled_at', 'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => EnrollmentStatus::class,
            'source' => EnrollmentSource::class,
            'progress_percentage' => 'integer',
            'enrolled_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    public function progress(): HasMany
    {
        return $this->hasMany(LessonProgress::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', EnrollmentStatus::Active->value);
    }

    /**
     * Enrollments that grant runtime course access: active OR completed. A learner who has finished
     * the course keeps read/launch access to its lessons and curriculum; only cancelled (and
     * soft-deleted) rows lose it. The stricter active() scope stays for roster/entitlement checks.
     *
     * @param  Builder<Enrollment>  $query
     * @return Builder<Enrollment>
     */
    public function scopeGrantsAccess(Builder $query): Builder
    {
        return $query->whereIn('status', [
            EnrollmentStatus::Active->value,
            EnrollmentStatus::Completed->value,
        ]);
    }

    public function isActive(): bool
    {
        return $this->status === EnrollmentStatus::Active;
    }

    public function courseId(): int
    {
        return (int) $this->getAttribute('course_id');
    }

    public function progressPercentage(): int
    {
        return (int) $this->getAttribute('progress_percentage');
    }

    public function publicId(): string
    {
        return (string) $this->getAttribute('public_id');
    }

    public function statusEnum(): EnrollmentStatus
    {
        $status = $this->getAttribute('status');

        return $status instanceof EnrollmentStatus ? $status : EnrollmentStatus::from((string) $status);
    }

    protected static function newFactory(): EnrollmentFactory
    {
        return EnrollmentFactory::new();
    }
}
