<?php

namespace App\Contexts\Commerce\Exceptions;

/**
 * Raised when a coupon is well-formed and in-window but not eligible for THIS user or cart:
 * a per-user redemption cap is reached, the coupon is first-order-only and the user already
 * has a paid order, or the cart subtotal is below the coupon's minimum. Money is integer minor
 * units only.
 */
class CouponNotEligibleException extends CommerceException
{
    protected string $errorCode = 'COMMERCE_COUPON_NOT_ELIGIBLE';

    protected int $status = 422;

    public static function perUserLimit(int $limit): self
    {
        return new self("You have already used this coupon the maximum of {$limit} time(s).");
    }

    public static function firstOrderOnly(): self
    {
        return new self('This coupon is valid on your first order only.');
    }

    public static function belowMinSubtotal(int $minSubtotalMinor): self
    {
        return new self("This coupon requires a minimum subtotal of {$minSubtotalMinor} (minor units).");
    }
}
