<?php

namespace App\Platform\Identity\Enums;

/**
 * How an organization treats sign-ins from an email domain it has claimed.
 *
 *  - AutoJoin: a user signing in with this email domain is joined to the organization.
 *  - Restrict: only listed domains may SSO into the organization (an allow-list).
 */
enum SsoDomainMode: string
{
    case AutoJoin = 'auto_join';
    case Restrict = 'restrict';

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(fn (self $m) => $m->value, self::cases());
    }
}
