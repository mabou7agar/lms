<?php

namespace App\Domains\Assessment\Models;

use App\Domains\Assessment\Database\Factories\SubmissionFileFactory;
use App\Platform\Shared\Traits\HasPublicId;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A media file attached to a submission, referenced only by the Media asset's PUBLIC id (scalar).
 * The Media model is never imported; resolution/authorization goes through MediaReferencePort.
 *
 * @property int $id
 * @property string $public_id
 * @property int $submission_id
 * @property string $media_public_id
 * @property string|null $original_filename
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read AssignmentSubmission|null $submission
 */
class SubmissionFile extends Model
{
    /** @use HasFactory<SubmissionFileFactory> */
    use HasFactory;

    use HasPublicId;

    /** @var list<string> */
    protected $fillable = ['submission_id', 'media_public_id', 'original_filename'];

    /** @return BelongsTo<AssignmentSubmission, $this> */
    public function submission(): BelongsTo
    {
        return $this->belongsTo(AssignmentSubmission::class, 'submission_id');
    }

    protected static function newFactory(): SubmissionFileFactory
    {
        return SubmissionFileFactory::new();
    }
}
