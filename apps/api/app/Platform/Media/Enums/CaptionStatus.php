<?php

namespace App\Platform\Media\Enums;

/**
 * P2/W04 - Lifecycle of a caption/subtitle track attached to a media asset. Captions are metadata
 * only (an uploaded VTT/SRT reference) — the Media platform never transcribes.
 */
enum CaptionStatus: string
{
    case Pending = 'pending';
    case Ready = 'ready';
    case Failed = 'failed';

    public function isUsable(): bool
    {
        return $this === self::Ready;
    }
}
