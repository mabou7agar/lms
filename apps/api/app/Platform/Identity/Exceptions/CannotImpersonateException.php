<?php

namespace App\Platform\Identity\Exceptions;

/**
 * Raised when an impersonation attempt is refused. These guards are enforced in the
 * ImpersonationManager, below the authorization gate, so they hold even for super_admin
 * (whose central gate bypass would otherwise allow the action): a super_admin can never be
 * impersonated, nobody may impersonate themselves, and impersonation cannot be nested.
 */
class CannotImpersonateException extends IdentityException
{
    protected string $errorCode = 'IDENTITY_CANNOT_IMPERSONATE';

    protected int $status = 403;

    public static function self(): self
    {
        return new self('You cannot impersonate yourself.');
    }

    public static function superAdmin(): self
    {
        return new self('A super administrator cannot be impersonated.');
    }

    public static function alreadyActive(): self
    {
        return new self('You are already impersonating another user; leave that session first.');
    }

    public static function unauthorized(): self
    {
        return new self('You are not permitted to impersonate users.');
    }
}
