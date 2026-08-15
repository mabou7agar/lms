<?php

namespace App\Domains\Crm\Models;

use App\Domains\Crm\Enums\MemberRole;
use App\Domains\Crm\Enums\MemberStatus;
use App\Platform\Shared\Tenancy\Concerns\BelongsToTenant;
use App\Platform\Shared\Traits\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class OrganizationMember extends Model
{
    use BelongsToTenant;
    use HasPublicId;

    protected $fillable = [
        'organization_id', 'department_id', 'user_id', 'email', 'role', 'status',
        'invited_at', 'joined_at', 'invitation_token', 'invitation_expires_at',
    ];

    protected function casts(): array
    {
        return [
            'role' => MemberRole::class,
            'status' => MemberStatus::class,
            'invited_at' => 'datetime',
            'joined_at' => 'datetime',
            'invitation_expires_at' => 'datetime',
        ];
    }

    protected $hidden = ['invitation_token'];

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @return BelongsTo<Department, $this> */
    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    /** @return BelongsToMany<Team, $this> */
    public function teams(): BelongsToMany
    {
        return $this->belongsToMany(Team::class, 'crm_team_members', 'member_id', 'team_id')->withPivot('role');
    }

    public function isActive(): bool
    {
        return $this->getAttribute('status') === MemberStatus::Active;
    }
}
