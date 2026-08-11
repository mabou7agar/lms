<?php

namespace App\Platform\Notifications\Services;

use App\Platform\Notifications\Enums\EnrollmentStatus;
use App\Platform\Notifications\Enums\MarketingSendStatus;
use App\Platform\Notifications\Enums\NotificationCategory;
use App\Platform\Notifications\Models\CampaignEnrollment;
use App\Platform\Notifications\Models\CampaignSend;
use App\Platform\Notifications\Models\CampaignStep;
use App\Platform\Shared\Marketing\Data\MarketingRecipient;
use App\Platform\Shared\Services\BaseService;
use App\Platform\Shared\Tenancy\TenantScope;

/**
 * Advances due drip enrollments ONE step at a time. Runs in a system context (across tenants), so it
 * NEVER relies on the tenant global scope — each enrollment carries its own organization_id which is
 * threaded explicitly into the suppression/quiet-hours checks.
 *
 * Correctness properties:
 *   - No N+1: campaigns and their steps are eager-loaded, so per-enrollment work is a constant number
 *     of queries regardless of step count.
 *   - Idempotent + resumable: each (enrollment, step) has at most one campaign_sends row (unique
 *     index). A step already terminally sent is not re-sent on a re-run; a step advances current_step
 *     only after its send is recorded, so a crash mid-advance re-processes the same step safely.
 *   - Quiet-hours deferral does NOT advance the step: the enrollment's next_run_at is set to the
 *     window end and the SAME step is retried then (deferred, not dropped).
 */
class CampaignRunner extends BaseService
{
    public function __construct(private readonly MarketingDispatcher $dispatcher) {}

    /** Advance every enrollment whose next step is due. Returns how many were processed. */
    public function advanceDue(?int $limit = null): int
    {
        $query = CampaignEnrollment::query()
            ->withoutGlobalScope(TenantScope::class)
            ->where('status', EnrollmentStatus::Active->value)
            ->whereNotNull('next_run_at')
            ->where('next_run_at', '<=', now())
            ->with('campaign.steps')
            ->orderBy('id');

        if ($limit !== null) {
            $query->limit($limit);
        }

        $processed = 0;

        foreach ($query->get() as $enrollment) {
            $this->advanceOne($enrollment);
            $processed++;
        }

        return $processed;
    }

    public function advanceOne(CampaignEnrollment $enrollment): void
    {
        $campaign = $enrollment->campaign;

        if ($campaign === null) {
            return;
        }

        $nextPosition = $enrollment->current_step + 1;
        /** @var CampaignStep|null $step */
        $step = $campaign->steps->firstWhere('position', $nextPosition);

        if ($step === null) {
            $this->complete($enrollment);

            return;
        }

        $existing = CampaignSend::query()
            ->where('campaign_enrollment_id', $enrollment->id)
            ->where('campaign_step_id', $step->id)
            ->first();

        // Crash-safety: a step already terminally resolved is not re-sent; just settle the enrollment.
        if ($existing !== null && $existing->status !== MarketingSendStatus::Deferred) {
            $this->settleAfterOutcome($enrollment, $campaign, $step, $existing->status);

            return;
        }

        $recipient = new MarketingRecipient(
            recipientType: $enrollment->recipient_type,
            recipientId: $enrollment->recipient_id,
            email: $enrollment->email,
            timezone: $enrollment->timezone,
            locale: $enrollment->locale,
            hasConsent: $enrollment->consent_snapshot,
        );

        // Claim the (enrollment, step) as in-flight BEFORE the provider call. A crash between the send
        // and the outcome write can then never double-send: on the next tick the guard above sees a
        // non-Deferred row (this Sending claim) and treats the step as already handled.
        $this->recordSend($enrollment, $step, MarketingSendStatus::Sending, null, null);

        $result = $this->dispatcher->send(
            $enrollment->organization_id,
            $recipient,
            NotificationCategory::Marketing,
            $step->template_key,
            $step->channel,
            ['email' => $enrollment->email],
        );

        $this->recordSend($enrollment, $step, $result->status, $result->reason, $result->deferredUntil?->toDateTimeString());

        if ($result->wasDeferred()) {
            // Same step, later: retry at the window end. Step is NOT advanced.
            $enrollment->forceFill(['next_run_at' => $result->deferredUntil])->save();

            return;
        }

        $this->settleAfterOutcome($enrollment, $campaign, $step, $result->status);
    }

    private function settleAfterOutcome(
        CampaignEnrollment $enrollment,
        \App\Platform\Notifications\Models\MarketingCampaign $campaign,
        CampaignStep $step,
        MarketingSendStatus $status,
    ): void {
        // Suppressed / no-consent recipients EXIT the campaign — they receive no further steps.
        if ($status === MarketingSendStatus::SkippedSuppressed || $status === MarketingSendStatus::SkippedNoConsent) {
            $enrollment->forceFill([
                'status' => EnrollmentStatus::Suppressed->value,
                'current_step' => $step->position,
                'next_run_at' => null,
            ])->save();

            return;
        }

        // Sent: advance to the following step (or complete the drip).
        $following = $campaign->steps->firstWhere('position', $step->position + 1);

        if ($following === null) {
            $enrollment->forceFill([
                'current_step' => $step->position,
                'status' => EnrollmentStatus::Completed->value,
                'next_run_at' => null,
            ])->save();

            return;
        }

        $enrollment->forceFill([
            'current_step' => $step->position,
            'next_run_at' => now()->addMinutes($following->delay_minutes),
        ])->save();
    }

    private function complete(CampaignEnrollment $enrollment): void
    {
        $enrollment->forceFill([
            'status' => EnrollmentStatus::Completed->value,
            'next_run_at' => null,
        ])->save();
    }

    private function recordSend(
        CampaignEnrollment $enrollment,
        CampaignStep $step,
        MarketingSendStatus $status,
        ?string $reason,
        ?string $deferredUntil,
    ): void {
        CampaignSend::query()->updateOrCreate(
            [
                'campaign_enrollment_id' => $enrollment->id,
                'campaign_step_id' => $step->id,
            ],
            [
                'organization_id' => $enrollment->organization_id,
                'position' => $step->position,
                'email' => $enrollment->email,
                'status' => $status->value,
                'reason' => $reason,
                'deferred_until' => $deferredUntil,
                'sent_at' => $status === MarketingSendStatus::Sent ? now() : null,
            ],
        );
    }
}
