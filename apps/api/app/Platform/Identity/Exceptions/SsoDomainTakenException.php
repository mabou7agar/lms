<?php

namespace App\Platform\Identity\Exceptions;

/**
 * Raised when an org-admin tries to claim an email domain that is already mapped to an organization.
 * A domain is globally unique — exactly one org may own it — so a sign-in domain can never resolve
 * to two tenants.
 */
class SsoDomainTakenException extends IdentityException
{
    protected string $errorCode = 'SSO_DOMAIN_TAKEN';

    protected int $status = 422;

    public function __construct(string $message = 'This domain is already claimed by an organization.', array $details = [])
    {
        parent::__construct($message, $details);
    }
}
