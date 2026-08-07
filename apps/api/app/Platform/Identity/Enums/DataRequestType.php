<?php

namespace App\Platform\Identity\Enums;

/**
 * The data-subject request types mandated by PDPL/GDPR. `Access`/`Portability` export the subject's
 * data; `Erasure` pseudonymises it; `Rectification` is a correction request handled by staff.
 */
enum DataRequestType: string
{
    case Access = 'access';
    case Portability = 'portability';
    case Erasure = 'erasure';
    case Rectification = 'rectification';

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(static fn (self $t): string => $t->value, self::cases());
    }
}
