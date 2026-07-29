<?php

namespace App\Domains\Live\Models;

use App\Domains\Live\Enums\RegistrationStatus;
use App\Platform\Shared\Traits\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $user_id
 */
class SessionRegistration extends Model
{
    use HasPublicId;

    protected $fillable = ['session_id', 'user_id', 'status', 'registered_at'];

    protected function casts(): array
    {
        return ['status' => RegistrationStatus::class, 'registered_at' => 'datetime'];
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(LiveSession::class, 'session_id');
    }
}
