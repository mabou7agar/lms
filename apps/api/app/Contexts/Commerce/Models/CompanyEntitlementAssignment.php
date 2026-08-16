<?php

namespace App\Contexts\Commerce\Models;

use App\Platform\Shared\Traits\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One employee holding one seat in a company's purchased entitlement. Revoking sets `revoked_at`
 * rather than deleting the row, so the history of who held a licence survives reassignment.
 *
 * @property int $id
 * @property string $public_id
 * @property int $company_entitlement_id
 * @property int $organization_member_id
 * @property int $user_id
 * @property Carbon|null $assigned_at
 * @property Carbon|null $revoked_at
 * @property-read CompanyEntitlement|null $entitlement
 */
class CompanyEntitlementAssignment extends Model
{
    use HasPublicId;

    protected $fillable = [
        'company_entitlement_id', 'organization_member_id', 'user_id', 'assigned_at', 'revoked_at',
    ];

    protected function casts(): array
    {
        return [
            'organization_member_id' => 'integer',
            'user_id' => 'integer',
            'assigned_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<CompanyEntitlement, $this> */
    public function entitlement(): BelongsTo
    {
        return $this->belongsTo(CompanyEntitlement::class, 'company_entitlement_id');
    }

    public function isActive(): bool
    {
        return $this->revoked_at === null;
    }
}
