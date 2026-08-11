<?php

namespace App\Platform\Identity\Exceptions;

/**
 * Raised when unlinking a social account would leave the user with NO usable way to sign in (a
 * social-only account with one provider and no password). Unlinking must always leave at least one
 * credential, so this fails closed rather than orphaning the account.
 */
class LastSignInMethodException extends IdentityException
{
    protected string $errorCode = 'SSO_LAST_METHOD';

    protected int $status = 422;

    public function __construct(string $message = 'This is your only way to sign in. Set a password before unlinking this provider.', array $details = [])
    {
        parent::__construct($message, $details);
    }
}
