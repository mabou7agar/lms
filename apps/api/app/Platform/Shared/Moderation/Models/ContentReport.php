<?php

namespace App\Platform\Shared\Moderation\Models;

use App\Platform\Shared\Moderation\Enums\ReportReason;
use App\Platform\Shared\Moderation\Enums\ReportStatus;
use App\Platform\Shared\Traits\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;

/**
 * A single report raised against any reportable piece of user-generated content (a review, a Q&A
 * answer, a forum post — anything using the CanBeReported trait). The reportable target is a SCALAR
 * polymorphic reference (reportable_type/reportable_id), so this substrate carries no dependency on
 * the reporting domains and no FK on the target.
 *
 * Written only through the CanBeReported trait (creation) and ModerationService (resolution) via
 * forceFill; `id` and `public_id` are guarded so a client can never mass-assign an identity.
 *
 * @property int $id
 * @property string $public_id
 * @property string $reportable_type
 * @property int $reportable_id
 * @property int $reporter_user_id
 * @property ReportReason $reason
 * @property string|null $note
 * @property ReportStatus $status
 * @property int|null $resolved_by
 * @property Carbon|null $resolved_at
 */
class ContentReport extends Model
{
    use HasPublicId;

    protected $table = 'content_reports';

    protected $guarded = ['id', 'public_id'];

    protected function casts(): array
    {
        return [
            'reportable_id' => 'integer',
            'reporter_user_id' => 'integer',
            'reason' => ReportReason::class,
            'status' => ReportStatus::class,
            'resolved_by' => 'integer',
            'resolved_at' => 'datetime',
        ];
    }

    /** @return MorphTo<Model, $this> */
    public function reportable(): MorphTo
    {
        return $this->morphTo();
    }

    public function isOpen(): bool
    {
        return $this->status === ReportStatus::Pending;
    }
}
