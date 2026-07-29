<?php

namespace App\Platform\Shared\Analytics\Data;

/**
 * A single reported metric, carrying whether it could be computed at all.
 *
 * The whole point is to make "we cannot answer this" expressible without lying. A metric with no
 * data is NOT zero: nobody failing a quiz is not the same as nobody sitting one, and a revenue
 * figure of 0 for an instructor with no revenue backend is a false statement about their earnings.
 * Every unavailable metric carries a reason the UI can show verbatim.
 *
 * `available: false` with `value: null` is the only correct shape for an unsupported metric.
 * Callers must never substitute a default.
 */
final readonly class MetricValue
{
    private function __construct(
        public int|float|null $value,
        public bool $available,
        public ?string $reason = null,
    ) {}

    public static function of(int|float $value): self
    {
        return new self($value, true);
    }

    /**
     * Computed successfully but with an empty denominator — e.g. a pass rate with no graded
     * attempts. Distinct from `unavailable()`: the metric IS supported, there is simply nothing to
     * measure yet, and the UI should say so rather than showing 0%.
     */
    public static function noData(string $reason): self
    {
        return new self(null, false, $reason);
    }

    /** Not supported by the backend at all. */
    public static function unavailable(string $reason): self
    {
        return new self(null, false, $reason);
    }

    /** @return array{value: int|float|null, available: bool, reason?: string} */
    public function toArray(): array
    {
        $payload = ['value' => $this->value, 'available' => $this->available];

        if ($this->reason !== null) {
            $payload['reason'] = $this->reason;
        }

        return $payload;
    }
}
