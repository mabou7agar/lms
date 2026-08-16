<?php

declare(strict_types=1);

namespace App\Domains\Qna\Models;

use App\Domains\Qna\Database\Factories\CourseQuestionFactory;
use App\Domains\Qna\Enums\QuestionStatus;
use App\Domains\Qna\Enums\QuestionVisibility;
use App\Domains\Qna\Tenancy\InheritsCourseTenancy;
use App\Platform\Shared\Moderation\Concerns\CanBeReported;
use App\Platform\Shared\Traits\HasPublicId;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * A learner-authored question anchored to a course (optionally to a lesson + timecode). Persistence
 * and small state helpers only — authorization lives in the policy, mutation in the actions.
 *
 * Tenancy: inherited from the owning Course via CourseTenantScope (T1 Option-N). `organization_id`
 * is a denormalised stamp written server-side by AskQuestionAction and is deliberately NOT fillable,
 * so a forged organization_id in a request payload can never bind. `body` is sanitized on the write
 * path (the actions), never on read.
 *
 * @property int $id
 * @property string $public_id
 * @property int $course_id
 * @property int|null $lesson_id
 * @property int $user_id
 * @property int|null $organization_id
 * @property string $title
 * @property string $body sanitized HTML
 * @property QuestionVisibility $visibility
 * @property int|null $lesson_timestamp_seconds
 * @property QuestionStatus $status
 * @property Carbon|null $pinned_at
 * @property int|null $accepted_answer_id
 * @property int $answers_count
 * @property Carbon|null $first_response_at
 * @property int|null $first_response_minutes
 * @property Carbon|null $closed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 */
class CourseQuestion extends Model
{
    use CanBeReported;

    /** @use HasFactory<CourseQuestionFactory> */
    use HasFactory;

    use HasPublicId;
    use InheritsCourseTenancy;
    use SoftDeletes;

    protected $table = 'course_questions';

    /**
     * organization_id is intentionally EXCLUDED: it is stamped transitively from the course
     * server-side and must never be mass-assigned. accepted_answer_id / answers_count / pinned_at /
     * status are moved only by their dedicated actions (direct attribute writes), not by user input.
     *
     * @var list<string>
     */
    protected $fillable = [
        'course_id', 'lesson_id', 'user_id', 'title', 'body', 'visibility', 'lesson_timestamp_seconds',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => QuestionStatus::class,
            'visibility' => QuestionVisibility::class,
            'pinned_at' => 'datetime',
            'first_response_at' => 'datetime',
            'first_response_minutes' => 'integer',
            'closed_at' => 'datetime',
            'lesson_timestamp_seconds' => 'integer',
            'answers_count' => 'integer',
            'organization_id' => 'integer',
        ];
    }

    /** @return HasMany<QuestionAnswer, $this> */
    public function answers(): HasMany
    {
        return $this->hasMany(QuestionAnswer::class, 'question_id');
    }

    /** @return BelongsTo<QuestionAnswer, $this> */
    public function acceptedAnswer(): BelongsTo
    {
        return $this->belongsTo(QuestionAnswer::class, 'accepted_answer_id');
    }

    public function isResolved(): bool
    {
        return $this->status === QuestionStatus::Resolved;
    }

    public function isPinned(): bool
    {
        return $this->pinned_at !== null;
    }

    public function isPrivate(): bool
    {
        return $this->visibility === QuestionVisibility::Private;
    }

    /**
     * Is the course team late? Only an unanswered question can be overdue — once somebody from the
     * course has replied the promise has been kept, however the thread ends afterwards.
     */
    public function isOverdue(int $slaHours, ?Carbon $now = null): bool
    {
        if ($this->first_response_at !== null || ! $this->status->awaitsResponse()) {
            return false;
        }

        $asked = $this->created_at;

        return $asked !== null && $asked->copy()->addHours(max(1, $slaHours))->isBefore($now ?? Carbon::now());
    }

    /**
     * Questions still waiting on the course team, oldest first — the instructor's queue and the
     * numerator of every SLA figure.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeAwaitingResponse(Builder $query): Builder
    {
        return $query->whereNull('first_response_at')
            ->where('status', QuestionStatus::Open->value);
    }

    /**
     * Unanswered for longer than the promise allows.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeOverdue(Builder $query, int $slaHours, ?Carbon $now = null): Builder
    {
        $cutoff = ($now ?? Carbon::now())->copy()->subHours(max(1, $slaHours));

        return $query->awaitingResponse()->where('created_at', '<', $cutoff);
    }

    /**
     * The questions a given user may read: everything public, plus their own private ones. Course
     * staff bypass this entirely — the controller decides that, because only it knows who is asking.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeReadableBy(Builder $query, int $userId): Builder
    {
        return $query->where(fn (Builder $q) => $q
            ->where('visibility', QuestionVisibility::Public->value)
            ->orWhere('user_id', $userId));
    }

    /**
     * Ordinary learner-facing questions: not moderation-hidden. Pinned-first / recency ordering is
     * applied by the controller so the same base scope serves every filter.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeVisible(Builder $query): Builder
    {
        return $query->where('status', '!=', QuestionStatus::Hidden->value);
    }

    protected static function newFactory(): CourseQuestionFactory
    {
        return CourseQuestionFactory::new();
    }
}
