<?php

namespace App\Domains\Assessment\Models;

use App\Domains\Assessment\Casts\PreservesFloatArray;
use App\Domains\Assessment\Database\Factories\AssignmentSubmissionFactory;
use App\Domains\Assessment\Enums\SubmissionStatus;
use App\Platform\Shared\Traits\HasPublicId;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * One learner attempt at an assignment. rubric_snapshot is the immutable rubric copied at submit.
 *
 * @property int $id
 * @property string $public_id
 * @property int $assignment_id
 * @property int $user_id
 * @property int $attempt_no
 * @property SubmissionStatus $status
 * @property Carbon|null $submitted_at
 * @property bool $is_late
 * @property array<string, mixed>|null $rubric_snapshot
 * @property string|null $text_response
 * @property string|null $external_url
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Assignment|null $assignment
 * @property-read Collection<int, SubmissionFile> $files
 * @property-read SubmissionGrade|null $grade
 * @property-read Collection<int, SubmissionGradeEvent> $gradeEvents
 */
class AssignmentSubmission extends Model
{
    /** @use HasFactory<AssignmentSubmissionFactory> */
    use HasFactory;

    use HasPublicId;

    /** @var list<string> */
    protected $fillable = [
        'assignment_id', 'user_id', 'attempt_no', 'status', 'submitted_at',
        'is_late', 'rubric_snapshot', 'text_response', 'external_url',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => SubmissionStatus::class,
            'submitted_at' => 'datetime',
            'is_late' => 'boolean',
            'rubric_snapshot' => PreservesFloatArray::class,
            'attempt_no' => 'integer',
        ];
    }

    /** @return BelongsTo<Assignment, $this> */
    public function assignment(): BelongsTo
    {
        return $this->belongsTo(Assignment::class);
    }

    /** @return HasMany<SubmissionFile, $this> */
    public function files(): HasMany
    {
        return $this->hasMany(SubmissionFile::class, 'submission_id');
    }

    /** @return HasOne<SubmissionGrade, $this> */
    public function grade(): HasOne
    {
        return $this->hasOne(SubmissionGrade::class, 'submission_id');
    }

    /** @return HasMany<SubmissionGradeEvent, $this> */
    public function gradeEvents(): HasMany
    {
        return $this->hasMany(SubmissionGradeEvent::class, 'submission_id')->orderByDesc('id');
    }

    /** A submitted attempt is immutable to the learner: only draft content may be edited. */
    public function isEditable(): bool
    {
        return $this->status->isDraft();
    }

    protected static function newFactory(): AssignmentSubmissionFactory
    {
        return AssignmentSubmissionFactory::new();
    }
}
