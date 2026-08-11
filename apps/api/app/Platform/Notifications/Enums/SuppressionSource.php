<?php

namespace App\Platform\Notifications\Enums;

enum SuppressionSource: string
{
    case UnsubscribeLink = 'unsubscribe_link';
    case Admin = 'admin';
    case Bounce = 'bounce';
    case Complaint = 'complaint';

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(fn (self $c) => $c->value, self::cases());
    }
}
