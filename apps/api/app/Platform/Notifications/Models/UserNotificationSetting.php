<?php

namespace App\Platform\Notifications\Models;

use App\Platform\Notifications\Enums\DigestFrequency;
use App\Platform\Shared\Traits\HasPublicId;
use Illuminate\Database\Eloquent\Model;

/**
 * @property bool $quiet_hours_enabled
 * @property string|null $quiet_hours_end
 * @property string|null $quiet_hours_start
 */
class UserNotificationSetting extends Model
{
    use HasPublicId;

    protected $fillable = [
        'user_id', 'locale', 'digest_frequency', 'timezone',
        'quiet_hours_enabled', 'quiet_hours_start', 'quiet_hours_end',
    ];

    protected function casts(): array
    {
        return [
            'digest_frequency' => DigestFrequency::class,
            'quiet_hours_enabled' => 'boolean',
        ];
    }
}
