<?php

namespace App\Contexts\Commerce\Enums;

/**
 * What happens to a certificate the learner already earned when the purchase is refunded. Separate
 * from access: revoking a credential someone genuinely earned is a deliberate choice, not a
 * side-effect of a billing event, so the admin states it per product.
 */
enum CertificateRefundPolicy: string
{
    case KeepValid = 'keep_valid';
    case MarkRefunded = 'mark_refunded';
    case Revoke = 'revoke';

    public function label(): string
    {
        return match ($this) {
            self::KeepValid => 'Certificate stays valid',
            self::MarkRefunded => 'Certificate stays verifiable but is flagged as refunded',
            self::Revoke => 'Revoke the certificate — verification will fail',
        };
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $c) => [$c->value => $c->label()])->all();
    }

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(fn (self $c) => $c->value, self::cases());
    }
}
