<?php

namespace App\Domains\Assessment\Models;

use App\Domains\Assessment\Database\Factories\AssessmentFactory;
use App\Domains\Assessment\Enums\AssessmentScope;
use App\Domains\Assessment\Enums\AssessmentStatus;
use App\Domains\Assessment\Enums\FeedbackMode;
use App\Domains\Assessment\Tenancy\InheritsCourseTenancy;
use App\Platform\Shared\Traits\HasPublicId;
use App\Platform\Shared\Traits\HasTranslations;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * A reusable, independently-versioned assessment. Owns its questions; is merely REFERENCED by a
 * lesson. Carries no rendering, no attempt logic and no grading — those live in Services/Grading.
 *
 * @property int $id
 * @property string $public_id
 * @property int|null $course_id null = platform-level bank (admin-managed)
 * @property string $title
 * @property string|null $description sanitized HTML
 * @property AssessmentScope $scope
 * @property AssessmentStatus $status
 * @property int|null $passing_score percentage 0-100; null = ungraded
 * @property bool $required_for_completion whether passing this assessment gates course completion
 * @property bool $negative_marking
 * @property int|null $max_attempts null = unlimited
 * @property int|null $time_limit_seconds null = untimed
 * @property bool $shuffle_questions
 * @property bool $shuffle_options
 * @property int|null $questions_per_attempt null = serve every question
 * @property FeedbackMode $feedback_mode
 * @property int $version
 * @property int|null $parent_assessment_id
 * @property int|null $created_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read Collection<int, AssessmentQuestion> $questions
 * @property-read Collection<int, AssessmentAttempt> $attempts
 * @property-read Collection<int, AssessmentTag> $tags
 * @property-read int|null $questions_count  present only when loaded via withCount('questions')
 * @property-read int|null $attempts_count
 */
class Assessment extends Model
{
    /** @use HasFactory<AssessmentFactory> */
    use HasFactory;

    use HasPublicId;
    use HasTranslations;

    // T1 Option-N tenancy inherited from the owning Course (assessments.course_id) — NO tenant column
    // on assessments, questions or options. CourseTenantScope filters to assessments whose course is
    // global or owned by the active tenant; dormant when no tenant is resolved (public catalog +
    // existing suite unchanged).
    use InheritsCourseTenancy;
    use SoftDeletes;

    /** @var list<string> */
    protected $fillable = [
        'course_id', 'title', 'title_i18n', 'description', 'description_i18n', 'scope', 'status',
        'passing_score', 'required_for_completion', 'negative_marking',
        'max_attempts', 'time_limit_seconds', 'shuffle_questions', 'shuffle_options',
        'questions_per_attempt', 'feedback_mode',
        'version', 'parent_assessment_id', 'created_by',
    ];

    /** @var array<int, string> */
    protected array $translatable = ['title_i18n', 'description_i18n'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'scope' => AssessmentScope::class,
            'status' => AssessmentStatus::class,
            'feedback_mode' => FeedbackMode::class,
            'passing_score' => 'integer',
            'required_for_completion' => 'boolean',
            'negative_marking' => 'boolean',
            'max_attempts' => 'integer',
            'time_limit_seconds' => 'integer',
            'shuffle_questions' => 'boolean',
            'shuffle_options' => 'boolean',
            'questions_per_attempt' => 'integer',
            'version' => 'integer',
            'title_i18n' => 'array',
            'description_i18n' => 'array',
        ];
    }

    /** @return HasMany<AssessmentQuestion, $this> */
    public function questions(): HasMany
    {
        return $this->hasMany(AssessmentQuestion::class)->orderBy('position');
    }

    /** @return HasMany<AssessmentAttempt, $this> */
    public function attempts(): HasMany
    {
        return $this->hasMany(AssessmentAttempt::class);
    }

    /** @return MorphToMany<AssessmentTag, $this> */
    public function tags(): MorphToMany
    {
        return $this->morphToMany(AssessmentTag::class, 'taggable', 'assessment_taggables', 'taggable_id', 'tag_id');
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopePublished(Builder $query): Builder
    {
        return $query->where('status', AssessmentStatus::Published->value);
    }

    public function isPublished(): bool
    {
        return $this->status === AssessmentStatus::Published;
    }

    public function isAttemptable(): bool
    {
        return $this->status->isAttemptable();
    }

    public function isTimed(): bool
    {
        return $this->time_limit_seconds !== null && $this->time_limit_seconds > 0;
    }

    /** Total points available across every question (before any per-attempt subsetting). */
    public function totalPoints(): float
    {
        return (float) $this->questions()->sum('points');
    }

    protected static function newFactory(): AssessmentFactory
    {
        return AssessmentFactory::new();
    }
}
