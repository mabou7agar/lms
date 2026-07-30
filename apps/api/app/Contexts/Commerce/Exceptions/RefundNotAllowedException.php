<?php

namespace App\Contexts\Commerce\Exceptions;

/**
 * Raised when a refund cannot be issued against an order: the order was never paid, the requested
 * amount is non-positive, or it exceeds the order's remaining refundable balance (paid total minus
 * the sum of its non-failed refunds). Money is integer minor units only.
 */
class RefundNotAllowedException extends OrderNotRefundableException
{
    public static function notPaid(string $orderPublicId): self
    {
        return new self("Order [{$orderPublicId}] is not in a refundable (paid) state.");
    }

    public static function invalidAmount(int $amountMinor): self
    {
        return new self("Refund amount [{$amountMinor}] must be a positive integer amount.");
    }

    public static function exceedsRemaining(int $amountMinor, int $remainingMinor): self
    {
        return new self("Refund amount [{$amountMinor}] exceeds the remaining refundable [{$remainingMinor}].");
    }

    public static function gatewayDeclined(string $orderPublicId): self
    {
        return new self("The gateway declined the refund for order [{$orderPublicId}].");
    }
}
