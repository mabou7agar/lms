<?php

namespace App\Domains\Assessment\Models;

use App\Platform\Shared\Traits\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Append-only grading-history row. No updates, no factory — written only by GradingService.
 *
 * @property int $id
 * @property string $public_id
 * @property int $submission_id
 * @property int $grader_id
 * @property string $event
 * @property string|null $score decimal:2
 * @property bool|null $passed
 * @property int $version
 * @property string|null $note
 * @property Carbon|null $created_at
 * @property-read AssignmentSubmission|null $submission
 */
class SubmissionGradeEvent extends Model
{
    use HasPublicId;

    public const UPDATED_AT = null;

    /** @var list<string> */
    protected $fillable = [
        'submission_id', 'grader_id', 'event', 'score', 'passed', 'version', 'note', 'created_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'score' => 'decimal:2',
            'passed' => 'boolean',
            'version' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<AssignmentSubmission, $this> */
    public function submission(): BelongsTo
    {
        return $this->belongsTo(AssignmentSubmission::class, 'submission_id');
    }
}
