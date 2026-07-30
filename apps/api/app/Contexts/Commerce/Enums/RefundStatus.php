<?php

namespace App\Contexts\Commerce\Enums;

/**
 * Lifecycle of a refund record. A refund is finalized (immutable) once Succeeded or Failed.
 */
enum RefundStatus: string
{
    case Pending = 'pending';
    case Succeeded = 'succeeded';
    case Failed = 'failed';

    public function isFinal(): bool
    {
        return $this === self::Succeeded || $this === self::Failed;
    }

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(fn (self $c) => $c->value, self::cases());
    }
}
