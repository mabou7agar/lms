<?php

namespace App\Platform\Shared\Moderation\Services;

use App\Platform\Shared\Moderation\Enums\ReportStatus;
use App\Platform\Shared\Moderation\Models\ContentReport;

/**
 * The single write path for resolving a content report. A moderator either marks it reviewed,
 * dismisses it, or records that action was taken; each transition stamps who resolved it and when.
 * The report row is otherwise immutable — the substrate keeps an auditable moderation trail.
 */
class ModerationService
{
    /** Mark a report reviewed (looked at, no violation of note). */
    public function resolve(ContentReport $report, int $moderatorId): ContentReport
    {
        return $this->transition($report, ReportStatus::Reviewed, $moderatorId);
    }

    /** Dismiss a report as not actionable. */
    public function dismiss(ContentReport $report, int $moderatorId): ContentReport
    {
        return $this->transition($report, ReportStatus::Dismissed, $moderatorId);
    }

    /** Record that the reported content was actioned (hidden/removed by the owning domain). */
    public function action(ContentReport $report, int $moderatorId): ContentReport
    {
        return $this->transition($report, ReportStatus::Actioned, $moderatorId);
    }

    private function transition(ContentReport $report, ReportStatus $status, int $moderatorId): ContentReport
    {
        $report->forceFill([
            'status' => $status->value,
            'resolved_by' => $moderatorId,
            'resolved_at' => now(),
        ])->save();

        return $report;
    }
}
