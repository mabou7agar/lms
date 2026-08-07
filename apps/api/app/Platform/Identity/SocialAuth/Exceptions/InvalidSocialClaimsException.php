<?php

namespace App\Platform\Identity\SocialAuth\Exceptions;

use App\Platform\Identity\Exceptions\IdentityException;

class InvalidSocialClaimsException extends IdentityException
{
    protected string $errorCode = 'SSO_CLAIMS_INVALID';

    protected int $status = 401;

    public function __construct(string $claim = '', array $details = [])
    {
        parent::__construct(
            $claim === '' ? 'The sign-in token could not be verified.' : "The sign-in token failed the [{$claim}] check.",
            $details === [] && $claim !== '' ? ['claim' => $claim] : $details,
        );
    }
}
