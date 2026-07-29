<?php

namespace App\Domains\Assessment\Models;

use App\Domains\Assessment\Database\Factories\SubmissionGradeFactory;
use App\Platform\Shared\Traits\HasPublicId;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * The current grade of a submission. `version` backs optimistic concurrency; `private_notes` is
 * grader-only and must never be serialized to a learner. `released_at` gates learner visibility.
 *
 * @property int $id
 * @property string $public_id
 * @property int $submission_id
 * @property int $grader_id
 * @property string|null $score decimal:2
 * @property bool|null $passed
 * @property string|null $feedback
 * @property string|null $private_notes
 * @property array<int, array<string, mixed>>|null $rubric_result
 * @property Carbon|null $released_at
 * @property int $version
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read AssignmentSubmission|null $submission
 */
class SubmissionGrade extends Model
{
    /** @use HasFactory<SubmissionGradeFactory> */
    use HasFactory;

    use HasPublicId;

    /** @var list<string> */
    protected $fillable = [
        'submission_id', 'grader_id', 'score', 'passed', 'feedback',
        'private_notes', 'rubric_result', 'released_at', 'version',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'score' => 'decimal:2',
            'passed' => 'boolean',
            'rubric_result' => 'array',
            'released_at' => 'datetime',
            'version' => 'integer',
        ];
    }

    public function isReleased(): bool
    {
        return $this->released_at !== null;
    }

    /** @return BelongsTo<AssignmentSubmission, $this> */
    public function submission(): BelongsTo
    {
        return $this->belongsTo(AssignmentSubmission::class, 'submission_id');
    }

    protected static function newFactory(): SubmissionGradeFactory
    {
        return SubmissionGradeFactory::new();
    }
}
