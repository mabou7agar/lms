<?php

namespace App\Platform\Identity\SocialAuth\Exceptions;

use App\Platform\Identity\Exceptions\IdentityException;

class UnknownSocialProviderException extends IdentityException
{
    protected string $errorCode = 'SSO_PROVIDER_UNKNOWN';

    protected int $status = 404;

    public function __construct(string $provider = '', array $details = [])
    {
        parent::__construct(
            $provider === '' ? 'Unknown sign-in provider.' : "Unknown sign-in provider [{$provider}].",
            $details,
        );
    }
}
