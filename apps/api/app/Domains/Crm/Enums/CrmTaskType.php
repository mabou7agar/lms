<?php

namespace App\Domains\Crm\Enums;

/**
 * Categorises a CRM task so the sales surface can filter and report on activity type.
 */
enum CrmTaskType: string
{
    case Call = 'call';
    case Email = 'email';
    case Meeting = 'meeting';
    case FollowUp = 'follow_up';
    case Other = 'other';

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(fn (self $c) => $c->value, self::cases());
    }
}
