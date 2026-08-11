<?php

namespace App\Domains\Crm\Models;

use App\Domains\Crm\Enums\MemberRole;
use App\Domains\Crm\Enums\MemberStatus;
use App\Platform\Shared\Tenancy\Concerns\BelongsToTenant;
use App\Platform\Shared\Traits\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function isActive(): bool
    {
        return $this->status === MemberStatus::Active;
    }
}
