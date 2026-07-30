<?php

namespace App\Contexts\Commerce\Enums;

/**
 * Lifecycle of a credit note. Issued/Void are final states; a finalized credit note is immutable.
 */
enum CreditNoteStatus: string
{
    case Draft = 'draft';
    case Issued = 'issued';
    case Void = 'void';

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(fn (self $c) => $c->value, self::cases());
    }
}
