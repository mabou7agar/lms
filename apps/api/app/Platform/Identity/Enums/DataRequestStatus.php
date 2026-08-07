<?php

namespace App\Platform\Identity\Enums;

/** Lifecycle of a data-subject request. */
enum DataRequestStatus: string
{
    case Pending = 'pending';
    case InProgress = 'in_progress';
    case Completed = 'completed';
    case Rejected = 'rejected';

    public function isOpen(): bool
    {
        return $this === self::Pending || $this === self::InProgress;
    }
}
