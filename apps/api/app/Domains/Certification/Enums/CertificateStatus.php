<?php

namespace App\Domains\Certification\Enums;

/**
 * `Expired` is DERIVED, never stored: a credential lapses by the clock reaching its expiry date, not
 * by anyone writing a row. Storing it would need a sweep to stay honest, and a certificate would be
 * wrong in the window between lapsing and the sweep running. Only Issued and Revoked are persisted.
 */
enum CertificateStatus: string
{
    case Issued = 'issued';
    case Revoked = 'revoked';
    case Expired = 'expired';

    public function isValid(): bool
    {
        return $this === self::Issued;
    }

    /**
     * The states a certificate can actually be written as.
     *
     * @return list<self>
     */
    public static function storable(): array
    {
        return [self::Issued, self::Revoked];
    }

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(fn (self $s) => $s->value, self::cases());
    }
}
