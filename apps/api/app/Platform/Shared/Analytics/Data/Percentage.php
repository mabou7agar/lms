<?php

namespace App\Platform\Shared\Analytics\Data;

/**
 * The one place the "share of a whole, as a percentage" arithmetic lives (M6). Analytics reports
 * had this `denominator > 0 ? round(($n / $d) * 100, 2) : 0.0` expression open-coded in seven
 * places; every one is now a call here, so the empty-denominator convention (0.0, not a division by
 * zero) and the rounding are defined once.
 *
 * This is the FLOAT, two-decimal reporting convention. It is deliberately NOT merged with
 * EnrollmentStats::completionRate(), which returns a whole-number int and null for an empty
 * denominator — a different presentation for a different surface. Merging them would silently change
 * those API responses.
 */
final class Percentage
{
    /**
     * @param  int|float  $numerator
     * @param  int|float  $denominator
     */
    public static function rate($numerator, $denominator, int $precision = 2): float
    {
        if ($denominator > 0) {
            return round(($numerator / $denominator) * 100, $precision);
        }

        return 0.0;
    }
}
