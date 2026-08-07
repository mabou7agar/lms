<?php

namespace App\Platform\Identity\SocialAuth\Exceptions;

use App\Platform\Identity\Exceptions\IdentityException;

class SocialProviderDisabledException extends IdentityException
{
    protected string $errorCode = 'SSO_PROVIDER_DISABLED';

    protected int $status = 404;

    public function __construct(string $provider = '', array $details = [])
    {
        parent::__construct(
            $provider === '' ? 'This sign-in provider is disabled.' : "The [{$provider}] sign-in provider is disabled.",
            $details,
        );
    }
}
