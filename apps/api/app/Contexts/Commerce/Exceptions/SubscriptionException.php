<?php

namespace App\Contexts\Commerce\Exceptions;

use RuntimeException;

/**
 * Raised when a subscription operation cannot proceed: the plan has no price in the requested
 * currency, the first-period (or upgrade proration) charge was declined by the gateway, or a plan
 * change / reactivation is not valid for the subscription's current state. Money is integer minor
 * units only.
 */
class SubscriptionException extends RuntimeException
{
    public static function missingPrice(string $planPublicId, string $currency): self
    {
        return new self("Plan [{$planPublicId}] has no price for currency [{$currency}].");
    }

    public static function chargeFailed(string $subscriptionPublicId): self
    {
        return new self("The gateway declined the charge for subscription [{$subscriptionPublicId}].");
    }

    public static function invalidPlanChange(string $subscriptionPublicId): self
    {
        return new self("The requested plan change is not valid for subscription [{$subscriptionPublicId}].");
    }

    public static function notReactivatable(string $subscriptionPublicId): self
    {
        return new self("Subscription [{$subscriptionPublicId}] cannot be reactivated in its current state.");
    }

    public static function invalidSeatCount(int $seats): self
    {
        return new self("An organization subscription needs at least one seat; [{$seats}] requested.");
    }

    public static function seatDowngradeBelowAssigned(string $subscriptionPublicId, int $requested, int $assigned): self
    {
        return new self(
            "Subscription [{$subscriptionPublicId}] cannot be resized to [{$requested}] seats: "
            ."[{$assigned}] are currently assigned. Unassign employees first."
        );
    }

    public static function notAnOrganizationSubscription(string $subscriptionPublicId): self
    {
        return new self("Subscription [{$subscriptionPublicId}] is not an organization subscription.");
    }
}
