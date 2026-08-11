<?php

namespace App\Platform\Notifications\Enums;

/**
 * Terminal (or deferred) outcome of a single marketing step send. Recorded on campaign_sends so the
 * ledger is truthful about what actually happened to each recipient at each step.
 */
enum MarketingSendStatus: string
{
    // In-flight claim written BEFORE the provider call so a crash mid-send can never double-send. The
    // runner overwrites it with the real outcome once the send returns, and treats a leftover Sending
    // row (from a crashed send) as already handled — the step is advanced, never re-sent.
    case Sending = 'sending';
    case Sent = 'sent';
    // Inside quiet hours: NOT dropped — the enrollment is re-scheduled to the window end and this
    // send is retried then. Carries a deferred_until on the send row.
    case Deferred = 'deferred';
    case SkippedSuppressed = 'skipped_suppressed';
    case SkippedNoConsent = 'skipped_no_consent';

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(fn (self $c) => $c->value, self::cases());
    }
}
