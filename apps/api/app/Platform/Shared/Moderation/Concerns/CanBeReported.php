<?php

namespace App\Platform\Shared\Moderation\Concerns;

use App\Platform\Shared\Moderation\Enums\ReportReason;
use App\Platform\Shared\Moderation\Enums\ReportStatus;
use App\Platform\Shared\Moderation\Models\ContentReport;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * Opt-in seam that makes any Eloquent model reportable into the shared moderation queue
 * (content_reports) without that model's domain depending on the reporting UI or on other domains.
 *
 * PUBLIC API (relied upon by the Reviews, Qna and Forum domains — keep stable):
 *   - reports(): MorphMany  — every report raised against this record.
 *   - report(int $reporterUserId, ReportReason $reason, ?string $note = null): ContentReport
 *       Idempotent-ish: a reporter can hold at most ONE open (pending) report per target at a time;
 *       a repeat call while a pending report exists returns that same report instead of stacking
 *       duplicates. Once the moderator resolves/dismisses it, the reporter may report again.
 *
 * @mixin Model
 */
trait CanBeReported
{
    /** @return MorphMany<ContentReport, $this> */
    public function reports(): MorphMany
    {
        return $this->morphMany(ContentReport::class, 'reportable');
    }

    public function report(int $reporterUserId, ReportReason $reason, ?string $note = null): ContentReport
    {
        $existing = $this->reports()
            ->where('reporter_user_id', $reporterUserId)
            ->where('status', ReportStatus::Pending->value)
            ->first();

        if ($existing instanceof ContentReport) {
            return $existing;
        }

        $report = new ContentReport;
        $report->forceFill([
            'reportable_type' => $this->getMorphClass(),
            'reportable_id' => $this->getKey(),
            'reporter_user_id' => $reporterUserId,
            'reason' => $reason->value,
            'note' => $note,
            'status' => ReportStatus::Pending->value,
        ])->save();

        return $report;
    }
}
