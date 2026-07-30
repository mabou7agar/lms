<?php

namespace Tests\Feature\Coupons;

use App\Contexts\Commerce\Enums\OrderStatus;
use App\Contexts\Commerce\Exceptions\CouponNotEligibleException;
use App\Contexts\Commerce\Models\Coupon;
use App\Contexts\Commerce\Models\Order;
use App\Contexts\Commerce\Services\CouponService;
use App\Platform\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * CouponService promotion rules layered on top of the base validity window: minimum subtotal,
 * first-order-only, and the per-user redemption cap. Read-only validation — no redemption is
 * recorded here. Money is integer minor units.
 */
class CouponServiceTest extends TestCase
{
    use RefreshDatabase;

    private function service(): CouponService
    {
        return app(CouponService::class);
    }

    private function coupon(string $code, array $overrides = []): Coupon
    {
        return Coupon::factory()->create(array_replace([
            'code' => $code,
            'is_active' => true,
            'starts_at' => null,
            'ends_at' => null,
            'max_redemptions' => null,
            'redeemed_count' => 0,
            'per_user_limit' => null,
            'first_order_only' => false,
            'min_subtotal_minor' => null,
        ], $overrides));
    }

    private function order(User $user, bool $paid): Order
    {
        $order = Order::create([
            'user_id' => $user->id,
            'status' => ($paid ? OrderStatus::Paid : OrderStatus::Pending)->value,
            'currency' => 'SAR',
            'subtotal_minor' => 10000,
            'discount_minor' => 0,
            'tax_minor' => 0,
            'total_minor' => 10000,
            'placed_at' => now(),
        ]);

        if ($paid) {
            $order->forceFill(['paid_at' => now()])->save();
        }

        return $order;
    }

    public function test_below_minimum_subtotal_is_rejected(): void
    {
        $user = User::factory()->create();
        $this->coupon('MIN50', ['min_subtotal_minor' => 5000]);

        $this->expectException(CouponNotEligibleException::class);

        $this->service()->validateForUser('MIN50', $user->id, 4000);
    }

    public function test_meeting_minimum_subtotal_passes(): void
    {
        $user = User::factory()->create();
        $coupon = $this->coupon('MIN50', ['min_subtotal_minor' => 5000]);

        $validated = $this->service()->validateForUser('MIN50', $user->id, 5000);

        $this->assertSame($coupon->getKey(), $validated->getKey());
    }

    public function test_first_order_only_rejects_a_returning_buyer(): void
    {
        $user = User::factory()->create();
        $this->order($user, paid: true);
        $this->coupon('WELCOME', ['first_order_only' => true]);

        $this->expectException(CouponNotEligibleException::class);

        $this->service()->validateForUser('WELCOME', $user->id, 10000);
    }

    public function test_first_order_only_allows_a_first_time_buyer(): void
    {
        $user = User::factory()->create();
        $coupon = $this->coupon('WELCOME', ['first_order_only' => true]);

        $validated = $this->service()->validateForUser('WELCOME', $user->id, 10000);

        $this->assertSame($coupon->getKey(), $validated->getKey());
    }

    public function test_per_user_limit_is_enforced_against_prior_redemptions(): void
    {
        $user = User::factory()->create();
        $coupon = $this->coupon('ONCE', ['per_user_limit' => 1]);
        $order = $this->order($user, paid: true);

        $coupon->redemptions()->create(['user_id' => $user->id, 'order_id' => $order->id]);

        $this->expectException(CouponNotEligibleException::class);

        $this->service()->validateForUser('ONCE', $user->id, 10000);
    }

    public function test_per_user_limit_does_not_block_a_different_user(): void
    {
        $redeemer = User::factory()->create();
        $other = User::factory()->create();
        $coupon = $this->coupon('ONCE', ['per_user_limit' => 1]);
        $order = $this->order($redeemer, paid: true);

        $coupon->redemptions()->create(['user_id' => $redeemer->id, 'order_id' => $order->id]);

        $validated = $this->service()->validateForUser('ONCE', $other->id, 10000);

        $this->assertSame($coupon->getKey(), $validated->getKey());
    }
}
