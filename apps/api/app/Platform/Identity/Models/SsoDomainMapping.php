<?php

namespace App\Platform\Identity\Models;

use App\Platform\Identity\Enums\SsoDomainMode;
use App\Platform\Shared\Traits\HasPublicId;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * An email domain claimed by an organization for SSO. The domain is GLOBALLY unique (one org per
 * domain), so a sign-in domain can never resolve to two tenants.
 *
 * Owned by Identity (the layer that owns tenancy + the User model). External references use
 * `public_id`; the bigint id is internal only.
 *
 * @property int $organization_id
 * @property string $domain
 * @property \Illuminate\Support\Carbon|null $verified_at
 * @property Carbon|null $created_at
 * @property SsoDomainMode $mode
 * @property string $public_id
 */
class SsoDomainMapping extends Model
{
    use HasPublicId;

    protected $fillable = [
        'organization_id', 'domain', 'mode', 'verified_at', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'mode' => SsoDomainMode::class,
            'verified_at' => 'datetime',
            'organization_id' => 'integer',
            'created_by' => 'integer',
        ];
    }

    public function isVerified(): bool
    {
        return $this->verified_at !== null;
    }

    /**
     * @param  Builder<SsoDomainMapping>  $query
     * @return Builder<SsoDomainMapping>
     */
    public function scopeForOrganization(Builder $query, int $organizationId): Builder
    {
        return $query->where('organization_id', $organizationId);
    }
}
