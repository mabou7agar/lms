<?php

namespace App\Platform\Identity\SocialAuth\Exceptions;

use App\Platform\Identity\Exceptions\IdentityException;

class SocialEmailConflictException extends IdentityException
{
    protected string $errorCode = 'SSO_EMAIL_CONFLICT';

    protected int $status = 409;

    public function __construct(string $message = 'An account with this email already exists. Sign in with your password first, then link this provider.', array $details = [])
    {
        parent::__construct($message, $details);
    }
}
