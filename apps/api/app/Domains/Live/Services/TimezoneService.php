<?php

namespace App\Domains\Live\Services;

use App\Domains\Live\Exceptions\InvalidTimezoneException;
use App\Platform\Shared\Services\BaseService;
use App\Platform\Shared\Time\TimezoneResolver;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Timezone helpers for the Live domain. Thin adapter over the shared {@see TimezoneResolver}: the
 * boundary logic and DST policy live in one place, while this class keeps the Live-specific contract
 * (its own {@see InvalidTimezoneException} error code) that Live call-sites and tests depend on.
 *
 * Sessions are stored in UTC; presentation converts into the session zone.
 */
class TimezoneService extends BaseService
{
    public function __construct(
        private readonly TimezoneResolver $resolver = new TimezoneResolver,
    ) {}

    public function assertValid(string $timezone): void
    {
        if (! $this->resolver->isValid($timezone)) {
            throw new InvalidTimezoneException("Invalid timezone: {$timezone}");
        }
    }

    /** Interpret a wall-clock time in a zone and return the UTC instant. */
    public function toUtc(string $localDateTime, string $timezone): CarbonImmutable
    {
        $this->assertValid($timezone);

        return $this->resolver->toUtc($localDateTime, $timezone);
    }

    /** Present a UTC instant in the session's zone (ISO-8601). */
    public function inZone(CarbonInterface $utc, string $timezone): string
    {
        $this->assertValid($timezone);

        return $this->resolver->inZone($utc, $timezone);
    }
}
