<?php

namespace App\Contexts\Commerce\Actions\Coupon;

use App\Contexts\Commerce\Exceptions\CommerceException;
use App\Contexts\Commerce\Services\CouponService;
use App\Platform\Shared\Actions\BaseAction;
use Illuminate\Support\Collection;

/**
 * Public "validate coupon" use case: given a code, the authenticated user (0 for a guest preview),
 * and the current cart subtotal in minor units, it re-checks the coupon server-side (window, global
 * and per-user limits, first-order-only, minimum subtotal) and computes a discount PREVIEW. It never
 * records a redemption — the authoritative discount and cap are re-derived under a lock at checkout.
 *
 * The result is always shaped as {valid, discount_minor, message}: a rule failure is reported as
 * valid=false with a human-readable message rather than an error envelope, so the storefront can
 * show inline feedback. Money is integer minor units; the preview never exceeds the subtotal.
 */
class ValidateCouponAction extends BaseAction
{
    public function __construct(private readonly CouponService $coupons) {}

    /**
     * @return array{valid: bool, discount_minor: int, message: string}
     */
    public function execute(string $code, int $userId, int $subtotalMinor): array
    {
        try {
            $coupon = $this->coupons->validateForUser($code, $userId, $subtotalMinor);
        } catch (CommerceException $e) {
            return ['valid' => false, 'discount_minor' => 0, 'message' => $e->getMessage()];
        }

        // Preview the discount against the subtotal as a single eligible line. Product-scoped
        // coupons resolve to 0 here (no cart lines are supplied) and are computed exactly at
        // checkout; the value is never negative and never exceeds the subtotal.
        $discountMinor = $this->coupons->discountMinor($coupon, new Collection([
            ['product_id' => 0, 'amount_minor' => $subtotalMinor],
        ]));

        return ['valid' => true, 'discount_minor' => $discountMinor, 'message' => 'The coupon is valid.'];
    }
}
