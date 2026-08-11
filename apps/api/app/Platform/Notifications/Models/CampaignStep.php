<?php

namespace App\Platform\Notifications\Models;

use App\Platform\Notifications\Enums\Channel;
use App\Platform\Shared\Traits\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One step of a campaign. Scoped through its parent campaign (which is tenant-owned), so it carries
 * no tenant column of its own.
 *
 * @property int $id
 * @property int $marketing_campaign_id
 * @property int $position
 * @property int $delay_minutes
 * @property string $template_key
 * @property Channel $channel
 */
class CampaignStep extends Model
{
    use HasPublicId;

    protected $fillable = ['marketing_campaign_id', 'position', 'delay_minutes', 'template_key', 'channel'];

    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'delay_minutes' => 'integer',
            'channel' => Channel::class,
        ];
    }

    /** @return BelongsTo<MarketingCampaign, $this> */
    public function campaign(): BelongsTo
    {
        return $this->belongsTo(MarketingCampaign::class, 'marketing_campaign_id');
    }
}
