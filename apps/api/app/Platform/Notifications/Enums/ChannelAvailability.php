<?php

namespace App\Platform\Notifications\Enums;

/**
 * Whether a channel can actually deliver right now — resolved BEFORE a delivery is queued, so the
 * ledger records the truth immediately instead of queueing a doomed send.
 *
 *   Available    — flagged on and a working provider is configured; queue and attempt it.
 *   Disabled     — the channel is turned off, or it is optional (WhatsApp/Push/Webhooks) and has no
 *                  provider. Recorded as Skipped (Disabled). NOT a failure — an expected non-send.
 *   Misconfigured— a required channel (Email/SMS) is on but has no working provider. Recorded as
 *                  Failed (Configuration): retrying will not help until an operator fixes config.
 */
enum ChannelAvailability
{
    case Available;
    case Disabled;
    case Misconfigured;

    /** The delivery status a non-available channel is recorded with immediately (never queued). */
    public function terminalStatus(): DeliveryStatus
    {
        return match ($this) {
            self::Available => DeliveryStatus::Pending,
            self::Disabled => DeliveryStatus::SkippedDisabled,
            self::Misconfigured => DeliveryStatus::FailedConfiguration,
        };
    }
}
