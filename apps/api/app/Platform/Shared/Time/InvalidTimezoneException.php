<?php

declare(strict_types=1);

namespace App\Platform\Shared\Time;

use App\Platform\Shared\Exceptions\BaseDomainException;

/**
 * Raised when a timezone value is not a valid IANA identifier. Fixed UTC offsets and abbreviations
 * are rejected — only IANA zone names (e.g. Africa/Cairo, Asia/Riyadh) are accepted.
 */
final class InvalidTimezoneException extends BaseDomainException
{
    protected string $errorCode = 'TIMEZONE_INVALID';

    protected int $status = 422;

    public function __construct(string $timezone)
    {
        parent::__construct("Invalid timezone [{$timezone}]. Use an IANA identifier such as Africa/Cairo.");
    }
}
