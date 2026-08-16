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
use Illuminate\Support\Carbon;

/**
 * A learner's relationship with a course. Owns status and completion percentage. Entitlement
 * is granted by Learning actions (Commerce will call GrantEnrollmentAction later).
 *
 * `expires_at` is null for everything a learner obtained on their own terms — a purchase, a free
 * enrollment, a manual grant. Only a company seat carries a date, and only that date can take the
 * course away again.
 *
 * @property EnrollmentSource $source
 * @property Carbon|null $enrolled_at
 * @property Carbon|null $expires_at
 * @property Carbon|null $completed_at
 */
class Enrollment extends Model
{
    /** @use HasFactory<EnrollmentFactory> */
    use HasFactory;

    use HasPublicId;
    use SoftDeletes;

    protected $fillable = [
        'user_id', 'course_id', 'status', 'source', 'progress_percentage', 'enrolled_at', 'expires_at', 'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => EnrollmentStatus::class,
            'source' => EnrollmentSource::class,
            'progress_percentage' => 'integer',
            'enrolled_at' => 'datetime',
            'expires_at' => 'datetime',
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

    /**
     * Has this enrollment's access window elapsed? Only company-seat grants ever carry one, so an
     * individual purchase, a free enrollment and a manual grant all answer false forever.
     */
    public function hasExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    /**
     * Does this enrollment grant access right now — the status rule plus the access window? This is
     * the runtime check; scopeGrantsAccess() stays the query-side counterpart.
     */
    public function grantsAccessNow(): bool
    {
        return in_array($this->statusEnum(), [EnrollmentStatus::Active, EnrollmentStatus::Completed], true)
            && ! $this->hasExpired();
    }

    /**
     * Enrollments whose access window has not elapsed. Composes with grantsAccess()/active().
     *
     * @param  Builder<Enrollment>  $query
     * @return Builder<Enrollment>
     */
    public function scopeNotExpired(Builder $query): Builder
    {
        return $query->where(fn (Builder $q) => $q
            ->whereNull('expires_at')
            ->orWhere('expires_at', '>', now()));
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
