<?php

namespace App\Platform\Identity\SocialAuth\Exceptions;

use App\Platform\Identity\Exceptions\IdentityException;

class SocialEmailRequiredException extends IdentityException
{
    protected string $errorCode = 'SSO_EMAIL_REQUIRED';

    protected int $status = 422;

    public function __construct(string $message = 'The sign-in provider did not supply an email address, which is required to create an account.', array $details = [])
    {
        parent::__construct($message, $details);
    }
}
