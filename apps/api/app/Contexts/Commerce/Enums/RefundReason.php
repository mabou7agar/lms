<?php

namespace App\Contexts\Commerce\Enums;

/**
 * Business reason recorded for a refund, mirroring the categories most gateways accept.
 */
enum RefundReason: string
{
    case RequestedByCustomer = 'requested_by_customer';
    case Duplicate = 'duplicate';
    case Fraudulent = 'fraudulent';
    case Other = 'other';

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(fn (self $c) => $c->value, self::cases());
    }
}
