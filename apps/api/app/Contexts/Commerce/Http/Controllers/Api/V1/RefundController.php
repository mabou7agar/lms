<?php

namespace App\Contexts\Commerce\Http\Controllers\Api\V1;

use App\Contexts\Commerce\Actions\Refund\IssueRefundAction;
use App\Contexts\Commerce\Enums\RefundReason;
use App\Contexts\Commerce\Models\Order;
use App\Platform\Shared\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\Rule;

/**
 * Admin refunds endpoint. Thin: it validates the amount (optional; a full refund when omitted) and
 * reason, then delegates to IssueRefundAction. All money/immutability/idempotency rules live in the
 * domain action; there is no persistence here. The route is guarded by the
 * commerce.refunds.manage permission middleware. Money is integer minor units.
 */
class RefundController extends Controller
{
    public function refund(Request $request, Order $order, IssueRefundAction $action): JsonResponse
    {
        $validated = $request->validate([
            'amount_minor' => ['nullable', 'integer', 'min:1'],
            'reason' => ['nullable', Rule::enum(RefundReason::class)],
        ]);

        $reason = isset($validated['reason'])
            ? RefundReason::from((string) $validated['reason'])
            : RefundReason::RequestedByCustomer;

        $refund = $action->execute(
            $order,
            isset($validated['amount_minor']) ? (int) $validated['amount_minor'] : null,
            $reason,
        );

        $processedAt = $refund->getAttribute('processed_at');

        return ApiResponse::success([
            'id' => $refund->public_id,
            'order_id' => (string) $order->getAttribute('public_id'),
            'status' => $refund->statusEnum()->value,
            'amount_minor' => $refund->amountMinor(),
            'currency' => (string) $refund->getAttribute('currency'),
            'reason' => $refund->reasonEnum()?->value,
            'provider_reference' => $refund->getAttribute('provider_reference'),
            'processed_at' => $processedAt?->toIso8601String(),
        ], null, 201);
    }
}
