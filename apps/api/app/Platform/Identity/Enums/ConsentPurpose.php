<?php

namespace App\Platform\Identity\Enums;

/**
 * The distinct purposes a user may grant or withdraw consent for. Kept as an explicit allowlist so a
 * consent record can never reference an undefined purpose, and so PDPL/GDPR "specific, informed"
 * consent is auditable per purpose rather than as one blanket flag.
 */
enum ConsentPurpose: string
{
    case Terms = 'terms';
    case PrivacyPolicy = 'privacy_policy';
    case DataProcessing = 'data_processing';
    case Marketing = 'marketing';
    case Analytics = 'analytics';

    public function label(): string
    {
        return match ($this) {
            self::Terms => 'Terms of Service',
            self::PrivacyPolicy => 'Privacy Policy',
            self::DataProcessing => 'Data Processing',
            self::Marketing => 'Marketing Communications',
            self::Analytics => 'Analytics & Product Improvement',
        };
    }

    /** Purposes a user may freely toggle (as opposed to acceptance gates like Terms). */
    public function isWithdrawable(): bool
    {
        return match ($this) {
            self::Marketing, self::Analytics => true,
            default => false,
        };
    }

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(static fn (self $c): string => $c->value, self::cases());
    }
}
