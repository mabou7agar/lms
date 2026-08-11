<?php

namespace App\Domains\Crm\Models;

use App\Platform\Shared\Tenancy\Concerns\BelongsToTenant;
use App\Platform\Shared\Traits\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Department extends Model
{
    use BelongsToTenant;
    use HasPublicId;

    protected $table = 'crm_departments';

    protected $fillable = ['organization_id', 'name', 'manager_id'];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function teams(): HasMany
    {
        return $this->hasMany(Team::class);
    }

    public function members(): HasMany
    {
        return $this->hasMany(OrganizationMember::class);
    }

    public function managerId(): ?int
    {
        $id = $this->getAttribute('manager_id');

        return $id === null ? null : (int) $id;
    }
}
