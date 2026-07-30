<?php

namespace App\Contexts\Commerce\Enums;

/**
 * Status of a single payment attempt against an order or subscription renewal. Multiple attempts
 * may exist (retries / dunning); the parent order's status is authoritative, an attempt only
 * records one try.
 */
enum PaymentAttemptStatus: string
{
    case Pending = 'pending';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case Abandoned = 'abandoned';

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(fn (self $c) => $c->value, self::cases());
    }
}
