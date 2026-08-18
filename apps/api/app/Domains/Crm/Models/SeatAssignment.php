<?php

namespace App\Domains\Crm\Models;

use App\Platform\Shared\Traits\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property Carbon|null $assigned_at
 * @property int $member_id
 * @property string $public_id
 * @property mixed|null $revoked_at
 */
class SeatAssignment extends Model
{
    use HasPublicId;

    protected $fillable = ['seat_pool_id', 'member_id', 'assigned_at', 'revoked_at'];

    protected function casts(): array
    {
        return ['assigned_at' => 'datetime', 'revoked_at' => 'datetime'];
    }

    public function seatPool(): BelongsTo
    {
        return $this->belongsTo(SeatPool::class);
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(OrganizationMember::class, 'member_id');
    }

    public function isActive(): bool
    {
        return $this->revoked_at === null;
    }
}
