<?php

namespace App\Platform\Notifications\Models;

use App\Platform\Notifications\Enums\Channel;
use App\Platform\Notifications\Enums\DeliveryStatus;
use App\Platform\Shared\Traits\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $notification_id
 * @property Channel $channel
 * @property string|null $provider
 * @property DeliveryStatus $status
 * @property int $attempts
 * @property string|null $last_error
 * @property string|null $dedup_key
 * @property Carbon|null $sent_at
 * @property Carbon|null $dead_at
 */
class NotificationDelivery extends Model
{
    use HasPublicId;

    protected $fillable = [
        'notification_id', 'channel', 'provider', 'status', 'attempts', 'last_error', 'dedup_key', 'sent_at', 'dead_at',
    ];

    protected function casts(): array
    {
        return [
            'channel' => Channel::class,
            'status' => DeliveryStatus::class,
            'attempts' => 'integer',
            'sent_at' => 'datetime',
            'dead_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Notification, $this> */
    public function notification(): BelongsTo
    {
        return $this->belongsTo(Notification::class);
    }

    public function isPending(): bool
    {
        return $this->status === DeliveryStatus::Pending;
    }
}
