<?php

namespace App\Domains\Live\Models;

use App\Domains\Live\Enums\ReminderStatus;
use App\Platform\Shared\Traits\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property ReminderStatus $status
 * @property int $offset_minutes
 */
class SessionReminder extends Model
{
    use HasPublicId;

    protected $fillable = ['session_id', 'offset_minutes', 'channel', 'scheduled_at', 'status'];

    protected function casts(): array
    {
        return ['status' => ReminderStatus::class, 'scheduled_at' => 'datetime', 'offset_minutes' => 'integer'];
    }

    /** @return BelongsTo<LiveSession, $this> */
    public function session(): BelongsTo
    {
        return $this->belongsTo(LiveSession::class, 'session_id');
    }
}
