<?php

namespace App\Platform\Notifications\Enums;

/**
 * The lifecycle of one channel delivery.
 *
 * The invariant that gives this enum its reason to exist: `Sent` means a provider actually accepted
 * the message. A disabled or unconfigured channel is NEVER `Sent` — it is `SkippedDisabled` (the
 * channel is off, or optional-and-unconfigured) or `FailedConfiguration` (a required channel with
 * no working provider). The ledger must reflect what really happened.
 *
 * `Delivered` is reserved for a provider confirming final receipt. No provider reports that yet, so
 * nothing transitions to it today; the case exists so the contract is complete when receipts land.
 *
 * `Dead` is the dead-letter terminal for a delivery that exhausted its real send attempts.
 */
enum DeliveryStatus: string
{
    case Pending = 'pending';
    case Processing = 'processing';
    case Sent = 'sent';
    case Delivered = 'delivered';
    case Failed = 'failed';
    case SkippedDisabled = 'skipped_disabled';
    // Backing value kept <= 16 chars to fit notification_deliveries.status varchar(16). The human
    // label is "Failed (Configuration)"; this is only the stable stored identifier.
    case FailedConfiguration = 'failed_config';
    case Dead = 'dead';

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(fn (self $c) => $c->value, self::cases());
    }

    /** No further work will happen on a delivery in a terminal state. */
    public function isTerminal(): bool
    {
        return in_array($this, [
            self::Sent,
            self::Delivered,
            self::SkippedDisabled,
            self::FailedConfiguration,
            self::Dead,
        ], true);
    }

    /** A worker may claim a delivery for an attempt only from these states. */
    public function isClaimable(): bool
    {
        return $this === self::Pending || $this === self::Processing;
    }
}
