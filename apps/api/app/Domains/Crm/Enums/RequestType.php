<?php

namespace App\Domains\Crm\Enums;

/**
 * The intent behind a public enterprise-lead submission. Drives routing and lead scoring.
 */
enum RequestType: string
{
    case Demo = 'demo';
    case Pricing = 'pricing';
    case Contact = 'contact';
    case Partnership = 'partnership';

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(fn (self $c) => $c->value, self::cases());
    }
}
