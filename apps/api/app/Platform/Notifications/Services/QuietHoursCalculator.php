<?php

namespace App\Platform\Notifications\Services;

use App\Platform\Shared\Services\BaseService;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Throwable;

/**
 * Pure quiet-hours arithmetic. Given "now", a recipient timezone and a [start, end) window expressed
 * as "HH:MM" local times, it answers a single question: if a message would be delivered now, when may
 * it actually go out?
 *
 *   - Returns NULL when now is OUTSIDE the window (deliver immediately).
 *   - Returns the window END (as a UTC Carbon) when now is INSIDE the window (defer to then).
 *
 * Windows may wrap midnight (start > end, e.g. 21:00 -> 08:00). This class has no notion of category
 * or transactional bypass — callers decide whether quiet hours apply at all (only marketing does).
 */
class QuietHoursCalculator extends BaseService
{
    /**
     * The instant a message should be deferred to, or null if it may send now.
     */
    public function deferralUntil(
        CarbonInterface $now,
        ?string $timezone,
        ?string $start,
        ?string $end,
    ): ?CarbonInterface {
        if ($start === null || $end === null || $start === $end) {
            return null; // no (or empty) window configured
        }

        $tz = $this->safeTimezone($timezone);
        $local = CarbonImmutable::instance($now)->setTimezone($tz);

        [$startH, $startM] = $this->parse($start);
        [$endH, $endM] = $this->parse($end);

        $windowStart = $local->setTime($startH, $startM);
        $windowEnd = $local->setTime($endH, $endM);

        $wrapsMidnight = ($startH * 60 + $startM) > ($endH * 60 + $endM);

        if (! $wrapsMidnight) {
            // Same-day window: inside when start <= now < end.
            if ($local->greaterThanOrEqualTo($windowStart) && $local->lessThan($windowEnd)) {
                return $windowEnd->setTimezone('UTC');
            }

            return null;
        }

        // Overnight window (e.g. 21:00 -> 08:00): inside when now >= start OR now < end.
        if ($local->greaterThanOrEqualTo($windowStart)) {
            // Evening portion — the window ends tomorrow morning.
            return $windowEnd->addDay()->setTimezone('UTC');
        }

        if ($local->lessThan($windowEnd)) {
            // Early-morning portion — the window ends later today.
            return $windowEnd->setTimezone('UTC');
        }

        return null;
    }

    /** @return array{0:int,1:int} */
    private function parse(string $hhmm): array
    {
        $parts = explode(':', $hhmm);
        $h = (int) ($parts[0] ?? 0);
        $m = (int) ($parts[1] ?? 0);

        return [max(0, min(23, $h)), max(0, min(59, $m))];
    }

    private function safeTimezone(?string $timezone): string
    {
        if ($timezone === null || $timezone === '') {
            return (string) config('app.timezone', 'UTC');
        }

        try {
            CarbonImmutable::now($timezone);

            return $timezone;
        } catch (Throwable) {
            return (string) config('app.timezone', 'UTC');
        }
    }
}
