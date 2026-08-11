<?php

namespace App\Domains\Crm\Exceptions;

class InvalidInvitationException extends CrmException
{
    protected string $errorCode = 'CRM_INVITATION_INVALID';

    protected int $status = 422;

    public static function notFound(): self
    {
        return new self('This invitation is not valid.');
    }

    public static function expired(): self
    {
        return new self('This invitation has expired.');
    }

    public static function notPending(): self
    {
        return new self('This invitation is no longer pending.');
    }
}
