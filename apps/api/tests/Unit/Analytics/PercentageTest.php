<?php

use App\Platform\Shared\Analytics\Data\Percentage;

/**
 * M6 — the one rate calculator. These pin that it is byte-identical to the inline expression it
 * replaced across the reports: `denominator > 0 ? round(($n / $d) * 100, 2) : 0.0`.
 */
it('computes a two-decimal percentage identical to the old inline formula', function (int $n, int $d, float $expected) {
    expect(Percentage::rate($n, $d))->toBe($expected)
        ->and(Percentage::rate($n, $d))->toBe($d > 0 ? round(($n / $d) * 100, 2) : 0.0);
})->with([
    'half' => [1, 2, 50.0],
    'third rounds to 2dp' => [1, 3, 33.33],
    'full' => [3, 3, 100.0],
    'zero numerator' => [0, 5, 0.0],
    'empty denominator is 0.0, never a division error' => [5, 0, 0.0],
    'negative denominator guarded' => [5, -1, 0.0],
]);

it('honors a custom precision', function () {
    expect(Percentage::rate(1, 3, 4))->toBe(33.3333);
});
