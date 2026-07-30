<?php

namespace App\Contexts\Commerce\Enums;

/**
 * Kind of change recorded against a subscription's audit trail.
 */
enum SubscriptionChangeType: string
{
    case Created = 'created';
    case Renewal = 'renewal';
    case Upgrade = 'upgrade';
    case Downgrade = 'downgrade';
    case Cancellation = 'cancellation';
    case Reactivation = 'reactivation';
    case EnteredGrace = 'entered_grace';
    case Expired = 'expired';

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(fn (self $c) => $c->value, self::cases());
    }
}
