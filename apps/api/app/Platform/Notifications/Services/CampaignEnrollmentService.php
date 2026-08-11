<?php

namespace App\Platform\Notifications\Services;

use App\Platform\Notifications\Enums\EnrollmentStatus;
use App\Platform\Notifications\Models\CampaignEnrollment;
use App\Platform\Notifications\Models\MarketingCampaign;
use App\Platform\Shared\Marketing\Contracts\MarketingAudiencePort;
use App\Platform\Shared\Marketing\Data\MarketingRecipient;
use App\Platform\Shared\Services\BaseService;
use App\Platform\Shared\Tenancy\TenantScope;

/**
 * Enrols recipients into a campaign. Enrollment is IDEMPOTENT: the same (campaign, recipient) resolves
 * to the existing enrollment (unique index), so re-enrolling — from a re-fired automation or a
 * re-run segment sync — never creates a duplicate drip.
 */
class CampaignEnrollmentService extends BaseService
{
    public function __construct(private readonly MarketingAudiencePort $audience) {}

    /** Enrol a single recipient. Returns the (new or existing) enrollment. */
    public function enroll(MarketingCampaign $campaign, MarketingRecipient $recipient): CampaignEnrollment
    {
        $firstStep = $campaign->steps()->orderBy('position')->first();

        $nextRunAt = $firstStep === null
            ? null
            : now()->addMinutes($firstStep->delay_minutes);

        /** @var CampaignEnrollment $enrollment */
        $enrollment = CampaignEnrollment::query()
            ->withoutGlobalScope(TenantScope::class)
            ->firstOrCreate(
                [
                    'marketing_campaign_id' => $campaign->id,
                    'recipient_type' => $recipient->recipientType,
                    'recipient_id' => $recipient->recipientId,
                ],
                [
                    'organization_id' => $campaign->organization_id,
                    'email' => $recipient->email,
                    'timezone' => $recipient->timezone,
                    'locale' => $recipient->locale,
                    'consent_snapshot' => $recipient->hasConsent,
                    'current_step' => 0,
                    'status' => $firstStep === null ? EnrollmentStatus::Completed->value : EnrollmentStatus::Active->value,
                    'next_run_at' => $nextRunAt,
                ],
            );

        return $enrollment;
    }

    /**
     * Enrol a whole segment resolved by the owning context through the Shared port. Returns the
     * number of recipients processed. Tenant-scoped via the campaign's organization_id.
     */
    public function enrollSegment(MarketingCampaign $campaign): int
    {
        $count = 0;

        foreach ($this->audience->resolveSegment($campaign->organization_id, $campaign->audience_type, (array) $campaign->audience_filter) as $recipient) {
            $this->enroll($campaign, $recipient);
            $count++;
        }

        return $count;
    }
}
