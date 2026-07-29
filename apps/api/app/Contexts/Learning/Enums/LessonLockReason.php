<?php

namespace App\Contexts\Learning\Enums;

/**
 * Why a lesson is locked in the runtime curriculum view. Rendered as a stable scalar so clients can
 * branch on it without parsing prose. Order of precedence when several apply: Unpublished, then
 * Drip (scheduling), then Prerequisite (sequencing).
 */
enum LessonLockReason: string
{
    case Prerequisite = 'prerequisite_incomplete';
    case Drip = 'drip_not_released';
    case Unpublished = 'unpublished';

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(fn (self $r) => $r->value, self::cases());
    }
}
