<?php

namespace App\Platform\Notifications\Enums;

enum EnrollmentStatus: string
{
    case Active = 'active';
    case Completed = 'completed';
    case Cancelled = 'cancelled';
    case Suppressed = 'suppressed';

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(fn (self $c) => $c->value, self::cases());
    }

    /** Only active enrollments are advanced by the drip runner. */
    public function isAdvanceable(): bool
    {
        return $this === self::Active;
    }
}
