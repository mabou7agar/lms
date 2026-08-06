<?php

namespace App\Platform\Identity\Enums;

/**
 * Identity-scoped permissions (additive). Other domains define their own permission enums.
 */
enum Permission: string
{
    case ViewUsers = 'identity.users.view';
    case ManageUsers = 'identity.users.manage';
    case ImpersonateUsers = 'identity.users.impersonate';
    case ViewRoles = 'identity.roles.view';
    case ManageRoles = 'identity.roles.manage';

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(fn (self $p) => $p->value, self::cases());
    }
}
