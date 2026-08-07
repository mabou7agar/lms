<?php

namespace App\Platform\Identity\Models;

use App\Platform\Identity\Enums\DataRequestStatus;
use App\Platform\Identity\Enums\DataRequestType;
use App\Platform\Shared\Traits\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A PDPL/GDPR data-subject request (access, portability, erasure, rectification) and its lifecycle.
 */
class DataSubjectRequest extends Model
{
    use HasPublicId;

    protected $fillable = [
        'user_id', 'type', 'status', 'note', 'result', 'requested_at', 'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'type' => DataRequestType::class,
            'status' => DataRequestStatus::class,
            'result' => 'array',
            'requested_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
