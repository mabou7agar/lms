<?php

namespace App\Platform\Identity\SocialAuth\Exceptions;

use App\Platform\Identity\Exceptions\IdentityException;

class InvalidSocialStateException extends IdentityException
{
    protected string $errorCode = 'SSO_STATE_INVALID';

    protected int $status = 422;

    public function __construct(string $message = 'The sign-in request could not be verified. Please try again.', array $details = [])
    {
        parent::__construct($message, $details);
    }
}
