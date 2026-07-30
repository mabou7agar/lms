<?php

namespace App\Contexts\Commerce\Enums;

/**
 * Lifecycle of a recurring subscription.
 *
 * Trialing/Active/Grace all grant entitlements; PastDue is a transient state during a failed
 * renewal (dunning) that still grants access until the grace window closes; Canceled means the
 * learner opted out but keeps access until period end; Expired/Paused grant nothing.
 */
enum SubscriptionStatus: string
{
    case Trialing = 'trialing';
    case Active = 'active';
    case PastDue = 'past_due';
    case Grace = 'grace';
    case Canceled = 'canceled';
    case Expired = 'expired';
    case Paused = 'paused';

    /** Whether this status currently grants course entitlements. */
    public function grantsAccess(): bool
    {
        return match ($this) {
            self::Trialing, self::Active, self::PastDue, self::Grace, self::Canceled => true,
            self::Expired, self::Paused => false,
        };
    }

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(fn (self $c) => $c->value, self::cases());
    }
}
