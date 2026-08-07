<?php

namespace App\Platform\Identity\SocialAuth\Exceptions;

use App\Platform\Identity\Exceptions\IdentityException;

class UnsupportedSocialDriverException extends IdentityException
{
    protected string $errorCode = 'SSO_DRIVER_UNSUPPORTED';

    protected int $status = 500;

    public function __construct(string $driver = '', array $details = [])
    {
        parent::__construct(
            $driver === '' ? 'Unsupported sign-in driver.' : "Unsupported sign-in driver [{$driver}].",
            $details,
        );
    }
}
