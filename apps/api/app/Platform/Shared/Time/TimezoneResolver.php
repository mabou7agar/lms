<?php

declare(strict_types=1);

namespace App\Platform\Shared\Time;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Shared, app-wide timezone boundary service. Canonical rule: persisted timestamps are UTC;
 * conversion happens only at boundaries (user input -> UTC on the way in; UTC -> resolved zone on
 * the way out). Only IANA identifiers are accepted.
 *
 * DST policy (explicit and tested):
 *   - Nonexistent local time (spring-forward gap): the wall clock is shifted FORWARD onto the
 *     first valid instant after the gap (PHP's native normalisation).
 *   - Ambiguous local time (fall-back repeated hour): resolves to the EARLIER of the two instants
 *     (the pre-transition offset), chosen deterministically.
 */
final class TimezoneResolver
{
    public function isValid(string $timezone): bool
    {
        return in_array($timezone, DateTimeZone::listIdentifiers(), true);
    }

    public function assertValid(string $timezone): void
    {
        if (! $this->isValid($timezone)) {
            throw new InvalidTimezoneException($timezone);
        }
    }

    /**
     * Resolve an effective timezone from an ordered set of candidates (e.g. user then organization),
     * falling back to the application default. Invalid candidates are skipped.
     */
    public function resolveFor(?string ...$candidates): string
    {
        foreach ($candidates as $candidate) {
            if (is_string($candidate) && $this->isValid($candidate)) {
                return $candidate;
            }
        }

        return $this->fallback();
    }

    public function fallback(): string
    {
        $default = (string) config('shared.default_timezone', 'UTC');

        return $this->isValid($default) ? $default : 'UTC';
    }

    /**
     * Interpret a wall-clock string in $timezone and return the canonical UTC instant, applying the
     * DST policy above.
     */
    public function toUtc(string $localDateTime, string $timezone): CarbonImmutable
    {
        $this->assertValid($timezone);

        $zone = new DateTimeZone($timezone);
        $wall = new DateTimeImmutable($localDateTime, $zone);

        // Ambiguous (fall-back) times: the instant one hour earlier renders to the same wall clock.
        // Returning that earlier instant is deterministic regardless of which of the two occurrences
        // PHP selects when constructing $wall.
        $earlier = (new DateTimeImmutable('@'.($wall->getTimestamp() - 3600)))->setTimezone($zone);

        if ($earlier->format('Y-m-d H:i:s') === $wall->format('Y-m-d H:i:s')) {
            $wall = $earlier;
        }

        return CarbonImmutable::createFromInterface($wall)->utc();
    }

    /** Present a UTC instant in $timezone as an ISO-8601 string (with the zone's offset). */
    public function inZone(CarbonInterface $utc, string $timezone): string
    {
        $this->assertValid($timezone);

        return CarbonImmutable::createFromInterface($utc)->setTimezone($timezone)->toIso8601String();
    }

    /** A wall clock is nonexistent when the zone shifts it forward (spring-forward gap). */
    public function isNonexistent(string $localDateTime, string $timezone): bool
    {
        $this->assertValid($timezone);

        $zone = new DateTimeZone($timezone);
        $wall = new DateTimeImmutable($localDateTime, $zone);

        return $wall->format('Y-m-d H:i:s') !== $this->canonicalInput($localDateTime);
    }

    /**
     * A wall clock is ambiguous when a neighbouring instant (±1h) renders to the same wall clock —
     * i.e. it occurs twice (fall-back). Checking both neighbours makes detection independent of
     * which occurrence PHP selects for $localDateTime.
     */
    public function isAmbiguous(string $localDateTime, string $timezone): bool
    {
        $this->assertValid($timezone);

        $zone = new DateTimeZone($timezone);
        $wall = new DateTimeImmutable($localDateTime, $zone);
        $target = $wall->format('Y-m-d H:i:s');

        foreach ([-3600, 3600] as $shift) {
            $neighbour = (new DateTimeImmutable('@'.($wall->getTimestamp() + $shift)))->setTimezone($zone);

            if ($neighbour->format('Y-m-d H:i:s') === $target) {
                return true;
            }
        }

        return false;
    }

    private function canonicalInput(string $localDateTime): string
    {
        return (new DateTimeImmutable($localDateTime, new DateTimeZone('UTC')))->format('Y-m-d H:i:s');
    }
}
