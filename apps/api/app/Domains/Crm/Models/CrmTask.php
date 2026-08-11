<?php

namespace App\Domains\Crm\Models;

use App\Domains\Crm\Enums\CrmTaskType;
use App\Domains\Crm\Enums\TaskStatus;
use App\Platform\Shared\Traits\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class CrmTask extends Model
{
    use HasPublicId;

    protected $fillable = [
        'taskable_type', 'taskable_id', 'title', 'type', 'status', 'priority',
        'due_at', 'completed_at', 'assigned_to',
    ];

    protected function casts(): array
    {
        return [
            'status' => TaskStatus::class,
            'type' => CrmTaskType::class,
            'due_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function taskable(): MorphTo
    {
        return $this->morphTo();
    }

    public function isComplete(): bool
    {
        return $this->status === TaskStatus::Done;
    }
}
