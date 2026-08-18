<?php

namespace App\Domains\Crm\Models;

use App\Platform\Shared\Tenancy\Concerns\BelongsToTenant;
use App\Platform\Shared\Traits\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * @property Carbon|null $created_at
 * @property int|null $department_id
 * @property string $name
 * @property string $public_id
 */
class Team extends Model
{
    use BelongsToTenant;
    use HasPublicId;

    protected $table = 'crm_teams';

    protected $fillable = ['organization_id', 'department_id', 'name', 'manager_id'];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function members(): BelongsToMany
    {
        return $this->belongsToMany(OrganizationMember::class, 'crm_team_members', 'team_id', 'member_id')->withPivot('role');
    }

    public function managerId(): ?int
    {
        $id = $this->getAttribute('manager_id');

        return $id === null ? null : (int) $id;
    }
}
