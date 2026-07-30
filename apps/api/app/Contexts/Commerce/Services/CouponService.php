<?php

namespace App\Contexts\Commerce\Services;

use App\Contexts\Commerce\Enums\CouponScope;
use App\Contexts\Commerce\Enums\CouponType;
use App\Contexts\Commerce\Exceptions\CouponExhaustedException;
use App\Contexts\Commerce\Exceptions\CouponExpiredException;
use App\Contexts\Commerce\Exceptions\CouponInvalidException;
use App\Contexts\Commerce\Exceptions\CouponNotEligibleException;
use App\Contexts\Commerce\Models\Coupon;
use App\Contexts\Commerce\Models\CouponRedemption;
use App\Contexts\Commerce\Models\Order;
use App\Platform\Shared\Services\BaseService;
use Illuminate\Support\Collection;

/**
 * Validates coupons and computes discounts. No redemption side effects here — redemption is
 * recorded under a lock during checkout.
 */
class CouponService extends BaseService
{
    public function findValid(string $code): Coupon
    {
        $coupon = Coupon::where('code', $code)->first();

        if ($coupon === null || ! $coupon->is_active) {
            throw new CouponInvalidException;
        }

        if (! $coupon->isWithinWindow()) {
            throw new CouponExpiredException;
        }

        if ($coupon->isExhausted()) {
            throw new CouponExhaustedException;
        }

        return $coupon;
    }

    /**
     * Validate a coupon for a specific user and cart subtotal, enforcing the promotion rules on
     * top of the base validity window/limit: minimum subtotal, first-order-only, and the per-user
     * redemption cap (counted from coupon_redemptions). Read-only — no redemption is recorded here;
     * the authoritative cap is re-checked under a row lock during checkout. Money is integer minor
     * units. Returns the valid Coupon or throws a CommerceException describing the failure.
     */
    public function validateForUser(string $code, int $userId, int $subtotalMinor): Coupon
    {
        $coupon = $this->findValid($code);

        $minSubtotal = $coupon->minSubtotalMinor();
        if ($minSubtotal !== null && $subtotalMinor < $minSubtotal) {
            throw CouponNotEligibleException::belowMinSubtotal($minSubtotal);
        }

        $this->assertPromotionRulesForUser($coupon, $userId);

        return $coupon;
    }

    /**
     * Re-assert the per-user promotion rules (first-order-only, per-user redemption cap) for a user.
     *
     * Split out so checkout can re-run it INSIDE the coupon row lock: the apply-time check in
     * validateForUser is racy (a user could apply once then check out repeatedly, or race two
     * concurrent checkouts, to exceed the cap or reuse a first-order-only coupon). Called under the
     * lockForUpdate on the coupon row, this serializes the count and closes that revenue leak.
     */
    public function assertPromotionRulesForUser(Coupon $coupon, int $userId): void
    {
        if ($coupon->isFirstOrderOnly() && $this->userHasPaidOrder($userId)) {
            throw CouponNotEligibleException::firstOrderOnly();
        }

        $perUserLimit = $coupon->perUserLimit();
        if ($perUserLimit !== null && $this->userRedemptionCount($coupon, $userId) >= $perUserLimit) {
            throw CouponNotEligibleException::perUserLimit($perUserLimit);
        }
    }

    /**
     * Discount (minor units) for a set of line items [{product_id, amount_minor}].
     *
     * @param  Collection<int, array{product_id: int, amount_minor: int}>  $lines
     */
    public function discountMinor(Coupon $coupon, Collection $lines): int
    {
        $eligible = $lines;

        if ($coupon->scope === CouponScope::Products) {
            $ids = $coupon->products()->pluck('products.id')->flip();
            $eligible = $lines->filter(fn ($l) => $ids->has($l['product_id']));
        }

        $base = (int) $eligible->sum('amount_minor');

        if ($base <= 0) {
            return 0;
        }

        return match ($coupon->type) {
            CouponType::Percentage => (int) floor($base * min(100, $coupon->value) / 100),
            CouponType::Fixed => min($coupon->value, $base),
        };
    }

    /** Whether the user already has at least one paid order (so they are not a first-time buyer). */
    private function userHasPaidOrder(int $userId): bool
    {
        return Order::query()
            ->where('user_id', $userId)
            ->whereNotNull('paid_at')
            ->exists();
    }

    /** How many times this user has already redeemed the given coupon. */
    private function userRedemptionCount(Coupon $coupon, int $userId): int
    {
        return CouponRedemption::query()
            ->where('coupon_id', $coupon->getKey())
            ->where('user_id', $userId)
            ->count();
    }
}
