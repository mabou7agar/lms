<?php

namespace App\Platform\Branding\Models;

use App\Platform\Shared\Traits\HasPublicId;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * A white-label host claimed by an organization. The host is GLOBALLY unique (one org per host) so an
 * incoming Host header can never resolve to two tenants. A host resolves to its org's brand only when
 * VERIFIED (verified_at set) — an unverified host falls back to the global brand. Verification is a
 * super_admin stub (no DNS/ACME). External references use public_id; the bigint id is internal only.
 *
 * @property int $id
 * @property string $public_id
 * @property int $organization_id
 * @property string $host
 * @property bool $is_primary
 * @property \Illuminate\Support\Carbon|null $verified_at
 * @property string $verification_token
 * @property int|null $created_by
 * @property Carbon|null $created_at
 */
class CustomDomain extends Model
{
    use HasPublicId;

    protected $fillable = [
        'organization_id', 'host', 'is_primary', 'verified_at', 'verification_token', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'organization_id' => 'integer',
            'is_primary' => 'boolean',
            'verified_at' => 'datetime',
            'created_by' => 'integer',
        ];
    }

    public function isVerified(): bool
    {
        return $this->verified_at !== null;
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeForOrganization(Builder $query, int $organizationId): Builder
    {
        return $query->where('organization_id', $organizationId);
    }

    /**
     * The org whose brand a host currently resolves to: a match requires the host AND a set verified_at.
     * An unverified host returns null so the resolver falls back to the global brand.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeVerifiedHost(Builder $query, string $host): Builder
    {
        return $query->where('host', $host)->whereNotNull('verified_at');
    }
}
