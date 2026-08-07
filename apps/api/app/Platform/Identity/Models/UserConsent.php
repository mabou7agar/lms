<?php

namespace App\Platform\Identity\Models;

use App\Platform\Identity\Enums\ConsentPurpose;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A user's current consent decision for one purpose, with the timestamps and policy version that
 * make it auditable under PDPL/GDPR.
 */
class UserConsent extends Model
{
    protected $fillable = [
        'user_id', 'purpose', 'granted', 'version', 'source_ip', 'granted_at', 'revoked_at',
    ];

    protected function casts(): array
    {
        return [
            'purpose' => ConsentPurpose::class,
            'granted' => 'boolean',
            'granted_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
