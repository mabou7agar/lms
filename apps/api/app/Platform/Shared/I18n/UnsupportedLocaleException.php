<?php

declare(strict_types=1);

namespace App\Platform\Shared\I18n;

use App\Platform\Shared\Exceptions\BaseDomainException;

/**
 * Raised when a translation write targets a locale outside the supported allowlist
 * (config/shared.php). Prevents arbitrary-key mass assignment into translatable JSON columns.
 */
final class UnsupportedLocaleException extends BaseDomainException
{
    protected string $errorCode = 'LOCALE_UNSUPPORTED';

    protected int $status = 422;

    public function __construct(string $locale)
    {
        parent::__construct("Unsupported locale [{$locale}].");
    }
}
