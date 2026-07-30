<?php

namespace App\Contexts\Commerce\Actions\Refund;

use App\Contexts\Commerce\Actions\Payment\RefundOrderAction;
use App\Contexts\Commerce\Enums\RefundReason;
use App\Contexts\Commerce\Models\Order;
use App\Contexts\Commerce\Models\Refund;
use App\Platform\Shared\Actions\BaseAction;

/**
 * Admin-facing use case: issue a refund (full or partial) against an order on behalf of a staff
 * operator. A thin orchestration seam over RefundOrderAction so the admin controller stays free of
 * domain logic — authorization is enforced by the route's permission middleware
 * (commerce.refunds.manage), and all money/immutability/idempotency rules live in the domain
 * action. Money is integer minor units; a null amount means a full remaining-balance refund.
 */
class IssueRefundAction extends BaseAction
{
    public function __construct(private readonly RefundOrderAction $refunds) {}

    public function execute(
        Order $order,
        ?int $amountMinor = null,
        RefundReason $reason = RefundReason::RequestedByCustomer,
    ): Refund {
        return $this->refunds->execute($order, $amountMinor, $reason);
    }
}
