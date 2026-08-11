<?php

namespace App\Platform\Notifications\Models;

use App\Platform\Notifications\Enums\EnrollmentStatus;
use App\Platform\Shared\Tenancy\Concerns\BelongsToTenant;
use App\Platform\Shared\Traits\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One recipient's position in a campaign drip. Tenant-scoped so isolation holds even when the drip
 * runner scans across enrollments.
 *
 * @property int $id
 * @property int|null $organization_id
 * @property int $marketing_campaign_id
 * @property string $recipient_type
 * @property int $recipient_id
 * @property string $email
 * @property string|null $timezone
 * @property string|null $locale
 * @property bool $consent_snapshot
 * @property int $current_step
 * @property EnrollmentStatus $status
 * @property Carbon|null $next_run_at
 */
class CampaignEnrollment extends Model
{
    use BelongsToTenant;
    use HasPublicId;

    protected $fillable = [
        'organization_id', 'marketing_campaign_id', 'recipient_type', 'recipient_id',
        'email', 'timezone', 'locale', 'consent_snapshot', 'current_step', 'status', 'next_run_at',
    ];

    protected function casts(): array
    {
        return [
            'consent_snapshot' => 'boolean',
            'current_step' => 'integer',
            'status' => EnrollmentStatus::class,
            'next_run_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<MarketingCampaign, $this> */
    public function campaign(): BelongsTo
    {
        return $this->belongsTo(MarketingCampaign::class, 'marketing_campaign_id');
    }
}
