<?php

namespace App\Contexts\Commerce\Enums;

/**
 * Whose marks appear on a certificate earned through a company purchase. Only meaningful when the
 * product is sold to companies; individual purchases always carry platform branding.
 */
enum CompanyCertificateBranding: string
{
    case HelbaronOnly = 'helbaron_only';
    case CompanyLogoAndHelbaron = 'company_logo_and_helbaron';
    case CompanyNameOnly = 'company_name_only';

    public function label(): string
    {
        return match ($this) {
            self::HelbaronOnly => 'HElbaron branding only',
            self::CompanyLogoAndHelbaron => 'Company logo alongside HElbaron',
            self::CompanyNameOnly => 'Company name only',
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
