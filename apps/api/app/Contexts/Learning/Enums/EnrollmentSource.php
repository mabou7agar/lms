<?php

namespace App\Contexts\Learning\Enums;

/**
 * How an enrollment was granted. Commerce will use Purchase later; Learning stays payment-free.
 *
 * `CompanySeat` is the one source whose access is not the learner's own: it was handed out from an
 * organization's purchased seat pool and can be revoked or expire with that purchase. Telling it
 * apart from `Purchase` is what keeps a learner's personally bought access safe from their
 * employer's clock.
 */
enum EnrollmentSource: string
{
    case Free = 'free';
    case Purchase = 'purchase';
    case Manual = 'manual';
    case Grant = 'grant';
    case CompanySeat = 'company_seat';

    /** Was this access handed out from an organization's purchase rather than earned by the learner? */
    public function isCompanySeat(): bool
    {
        return $this === self::CompanySeat;
    }

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(fn (self $s) => $s->value, self::cases());
    }
}
