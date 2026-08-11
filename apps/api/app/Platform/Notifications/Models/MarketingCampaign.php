<?php

namespace App\Platform\Notifications\Models;

use App\Platform\Notifications\Database\Factories\MarketingCampaignFactory;
use App\Platform\Notifications\Enums\CampaignStatus;
use App\Platform\Shared\Tenancy\Concerns\BelongsToTenant;
use App\Platform\Shared\Traits\HasPublicId;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A marketing drip campaign. Tenant-scoped (organization_id) so an org sees only its own campaigns.
 *
 * @property int $id
 * @property int|null $organization_id
 * @property string $name
 * @property string $audience_type
 * @property array<string, scalar>|null $audience_filter
 */
class MarketingCampaign extends Model
{
    /** @use HasFactory<MarketingCampaignFactory> */
    use BelongsToTenant;

    use HasFactory;
    use HasPublicId;

    protected $fillable = ['organization_id', 'name', 'status', 'audience_type', 'audience_filter'];

    protected function casts(): array
    {
        return [
            'status' => CampaignStatus::class,
            'audience_filter' => 'array',
        ];
    }

    /** @return HasMany<CampaignStep, $this> */
    public function steps(): HasMany
    {
        return $this->hasMany(CampaignStep::class)->orderBy('position');
    }

    /** @return HasMany<CampaignEnrollment, $this> */
    public function enrollments(): HasMany
    {
        return $this->hasMany(CampaignEnrollment::class);
    }

    protected static function newFactory(): MarketingCampaignFactory
    {
        return MarketingCampaignFactory::new();
    }
}
