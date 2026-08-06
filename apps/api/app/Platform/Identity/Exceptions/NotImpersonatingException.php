<?php

namespace App\Platform\Identity\Exceptions;

/**
 * Raised when a request tries to leave impersonation while no impersonation session is active
 * (or the original actor no longer exists). Signals that there is nothing to restore.
 */
class NotImpersonatingException extends IdentityException
{
    protected string $errorCode = 'IDENTITY_NOT_IMPERSONATING';

    protected int $status = 409;

    public function __construct()
    {
        parent::__construct('There is no active impersonation session to leave.');
    }
}
