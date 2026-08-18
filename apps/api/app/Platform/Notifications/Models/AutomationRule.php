<?php

namespace App\Platform\Notifications\Models;

use App\Platform\Notifications\Enums\AutomationTriggerType;
use App\Platform\Shared\Tenancy\Concerns\BelongsToTenant;
use App\Platform\Shared\Traits\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int|null $organization_id
 */
class AutomationRule extends Model
{
    // Tenant ownership: stamped organization_id on create and filtered to the active tenant. The
    // AutomationRunner additionally applies an EXPLICIT tenant fence (mirroring the webhook emitter)
    // so a null-tenant system run sees only platform-level rules rather than every org's.
    use BelongsToTenant;
    use HasPublicId;

    protected $fillable = ['organization_id', 'name', 'trigger_type', 'trigger_key', 'conditions', 'is_active'];

    protected function casts(): array
    {
        return ['trigger_type' => AutomationTriggerType::class, 'conditions' => 'array', 'is_active' => 'boolean'];
    }

    public function actions(): HasMany
    {
        return $this->hasMany(AutomationAction::class);
    }
}
