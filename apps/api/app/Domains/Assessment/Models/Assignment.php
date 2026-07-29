<?php

namespace App\Domains\Assessment\Models;

use App\Domains\Assessment\Database\Factories\AssignmentFactory;
use App\Domains\Assessment\Enums\AssignmentState;
use App\Domains\Assessment\Enums\LatePolicy;
use App\Domains\Assessment\Enums\SubmissionType;
use App\Platform\Shared\Traits\HasPublicId;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * An instructor-reviewed assignment. course_id is the authorization anchor; lesson_id the optional
 * curriculum placement. Both are scalar cross-context ids, never Eloquent relations.
 *
 * @property int $id
 * @property string $public_id
 * @property int $course_id
 * @property int|null $lesson_id
 * @property string $title
 * @property array<string, mixed>|null $instructions
 * @property SubmissionType $submission_type
 * @property array<int, string>|null $allowed_file_types
 * @property int|null $max_file_size bytes
 * @property int $max_files
 * @property int|null $attempt_limit null = unlimited
 * @property Carbon|null $due_at
 * @property LatePolicy $late_policy
 * @property int|null $late_penalty_percent
 * @property string $max_grade decimal:2
 * @property string|null $passing_grade decimal:2
 * @property int|null $rubric_id active rubric internal id (no FK)
 * @property AssignmentState $publish_state
 * @property bool $required_for_completion
 * @property int|null $created_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read Collection<int, AssignmentRubric> $rubrics
 * @property-read Collection<int, AssignmentSubmission> $submissions
 * @property-read AssignmentRubric|null $rubric
 */
class Assignment extends Model
{
    /** @use HasFactory<AssignmentFactory> */
    use HasFactory;

    use HasPublicId;
    use SoftDeletes;

    /** @var list<string> */
    protected $fillable = [
        'course_id', 'lesson_id', 'title', 'instructions', 'submission_type',
        'allowed_file_types', 'max_file_size', 'max_files', 'attempt_limit',
        'due_at', 'late_policy', 'late_penalty_percent', 'max_grade', 'passing_grade',
        'rubric_id', 'publish_state', 'required_for_completion', 'created_by',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'submission_type' => SubmissionType::class,
            'late_policy' => LatePolicy::class,
            'publish_state' => AssignmentState::class,
            'instructions' => 'array',
            'allowed_file_types' => 'array',
            'max_file_size' => 'integer',
            'max_files' => 'integer',
            'attempt_limit' => 'integer',
            'late_penalty_percent' => 'integer',
            'max_grade' => 'decimal:2',
            'passing_grade' => 'decimal:2',
            'rubric_id' => 'integer',
            'due_at' => 'datetime',
            'required_for_completion' => 'boolean',
        ];
    }

    /** @return HasMany<AssignmentRubric, $this> */
    public function rubrics(): HasMany
    {
        return $this->hasMany(AssignmentRubric::class);
    }

    /** @return HasMany<AssignmentSubmission, $this> */
    public function submissions(): HasMany
    {
        return $this->hasMany(AssignmentSubmission::class);
    }

    /** The active rubric (rubric_id), if any. Manual belongsTo — no FK on the column. */
    public function rubric(): ?AssignmentRubric
    {
        return $this->rubric_id === null
            ? null
            : AssignmentRubric::query()->find($this->rubric_id);
    }

    public function isPublished(): bool
    {
        return $this->publish_state === AssignmentState::Published;
    }

    public function isPastDue(?Carbon $at = null): bool
    {
        return $this->due_at !== null && $this->due_at->isBefore($at ?? now());
    }

    public function hasPassMark(): bool
    {
        return $this->passing_grade !== null;
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopePublished(Builder $query): Builder
    {
        return $query->where('publish_state', AssignmentState::Published->value);
    }

    protected static function newFactory(): AssignmentFactory
    {
        return AssignmentFactory::new();
    }
}
