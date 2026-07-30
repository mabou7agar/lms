<?php

use App\Contexts\Commerce\Exceptions\CouponNotEligibleException;
use App\Contexts\Commerce\Models\Coupon;
use App\Contexts\Commerce\Models\Order;
use App\Contexts\Commerce\Services\CouponService;
use App\Platform\Identity\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('rejects a per-user-limited coupon once the user has reached the cap', function () {
    $user = User::factory()->create();
    $coupon = Coupon::factory()->create(['per_user_limit' => 1]);
    $order = Order::factory()->create(['user_id' => $user->id]);
    $coupon->redemptions()->create(['user_id' => $user->id, 'order_id' => $order->id]);

    app(CouponService::class)->assertPromotionRulesForUser($coupon, $user->id);
})->throws(CouponNotEligibleException::class);

it('allows a per-user-limited coupon for a user who has not yet redeemed it', function () {
    $user = User::factory()->create();
    $coupon = Coupon::factory()->create(['per_user_limit' => 1]);

    expect(fn () => app(CouponService::class)->assertPromotionRulesForUser($coupon, $user->id))
        ->not->toThrow(Exception::class);
});

it('enforces exactly one redemption row per order at the database level', function () {
    $user = User::factory()->create();
    $coupon = Coupon::factory()->create();
    $order = Order::factory()->create(['user_id' => $user->id]);
    $coupon->redemptions()->create(['user_id' => $user->id, 'order_id' => $order->id]);

    // A second redemption row for the same order violates the unique backstop.
    $coupon->redemptions()->create(['user_id' => $user->id, 'order_id' => $order->id]);
})->throws(QueryException::class);
