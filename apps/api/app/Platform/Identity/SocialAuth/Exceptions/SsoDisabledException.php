<?php

namespace App\Platform\Identity\SocialAuth\Exceptions;

use App\Platform\Identity\Exceptions\IdentityException;

class SsoDisabledException extends IdentityException
{
    protected string $errorCode = 'SSO_DISABLED';

    protected int $status = 404;

    public function __construct(string $message = 'Single sign-on is not enabled.', array $details = [])
    {
        parent::__construct($message, $details);
    }
}
