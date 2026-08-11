<?php

declare(strict_types=1);

namespace App\Platform\Notifications\Data;

use App\Platform\Notifications\Enums\MarketingSendStatus;
use Carbon\CarbonInterface;

/**
 * Outcome of a single marketing send decision. `deferredUntil` is set only for Deferred (quiet
 * hours); `reason` carries a short human explanation for skips.
 */
final class MarketingSendResult
{
    public function __construct(
        public readonly MarketingSendStatus $status,
        public readonly ?CarbonInterface $deferredUntil = null,
        public readonly ?string $reason = null,
    ) {}

    public static function sent(): self
    {
        return new self(MarketingSendStatus::Sent);
    }

    public static function deferred(CarbonInterface $until): self
    {
        return new self(MarketingSendStatus::Deferred, $until, 'quiet_hours');
    }

    public static function suppressed(): self
    {
        return new self(MarketingSendStatus::SkippedSuppressed, null, 'suppressed');
    }

    public static function noConsent(): self
    {
        return new self(MarketingSendStatus::SkippedNoConsent, null, 'no_consent');
    }

    public function wasSent(): bool
    {
        return $this->status === MarketingSendStatus::Sent;
    }

    public function wasDeferred(): bool
    {
        return $this->status === MarketingSendStatus::Deferred;
    }
}
