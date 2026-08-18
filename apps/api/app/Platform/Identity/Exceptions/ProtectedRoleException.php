<?php

namespace App\Platform\Identity\Exceptions;

/**
 * Raised when an attempt is made to delete one of the protected system roles
 * (super_admin, admin, instructor, student). These roles are load-bearing for the platform's
 * access model, so their deletion is blocked at the model layer regardless of authorization —
 * this is the safety net behind the central super_admin gate bypass.
 */
class ProtectedRoleException extends IdentityException
{
    protected string $errorCode = 'IDENTITY_PROTECTED_ROLE';

    protected int $status = 422;

    public function __construct(string $roleName)
    {
        parent::__construct("The system role [{$roleName}] is protected and cannot be deleted.");
    }
}
