<?php

namespace App\Platform\Notifications\Models;

use App\Platform\Notifications\Enums\MarketingSendStatus;
use App\Platform\Shared\Traits\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * The per-(enrollment, step) send ledger row. Existence keyed on the enrollment+step makes each
 * step's send idempotent under retry/resume.
 *
 * @property int $id
 * @property int|null $organization_id
 * @property int $campaign_enrollment_id
 * @property int $campaign_step_id
 * @property int $position
 * @property string $email
 * @property MarketingSendStatus $status
 * @property Carbon|null $deferred_until
 * @property Carbon|null $sent_at
 */
class CampaignSend extends Model
{
    use HasPublicId;

    protected $fillable = [
        'organization_id', 'campaign_enrollment_id', 'campaign_step_id', 'position',
        'email', 'status', 'reason', 'deferred_until', 'sent_at',
    ];

    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'status' => MarketingSendStatus::class,
            'deferred_until' => 'datetime',
            'sent_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<CampaignEnrollment, $this> */
    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(CampaignEnrollment::class, 'campaign_enrollment_id');
    }
}
