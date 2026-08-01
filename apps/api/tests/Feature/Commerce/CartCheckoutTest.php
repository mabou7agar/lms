<?php

use App\Contexts\Commerce\Actions\Checkout\CheckoutAction;
use App\Contexts\Commerce\Exceptions\CheckoutInProgressException;
use App\Contexts\Commerce\Models\Coupon;
use App\Contexts\Commerce\Models\Order;
use App\Platform\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);
require_once __DIR__.'/CommerceHelpers.php';

it('adds to cart, shows totals, and checks out to a pending order', function () {
    [$course, $product] = courseProduct(19900);
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $this->postJson('/api/v1/cart', ['product' => $product->public_id])
        ->assertOk()->assertJsonPath('data.total_minor', 19900);

    $this->getJson('/api/v1/cart')->assertOk()->assertJsonPath('data.subtotal_minor', 19900);

    $checkout = $this->postJson('/api/v1/checkout')->assertCreated();
    expect($checkout->json('data.order.status'))->toBe('pending')
        ->and($checkout->json('data.contract_id'))->toBeString()
        ->and($checkout->json('data.payment.provider_reference'))->toBeString();
});

it('applies a coupon to reduce the total', function () {
    [$course, $product] = courseProduct(20000);
    $coupon = Coupon::factory()->percentage(25)->create();
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $this->postJson('/api/v1/cart', ['product' => $product->public_id, 'coupon_code' => $coupon->code])
        ->assertOk()->assertJsonPath('data.discount_minor', 5000)->assertJsonPath('data.total_minor', 15000);
});

it('rejects a concurrent checkout while one is already in flight (no double charge)', function () {
    [$course, $product] = courseProduct(19900);
    $user = User::factory()->create();
    Sanctum::actingAs($user);
    $this->postJson('/api/v1/cart', ['product' => $product->public_id])->assertOk();

    // Simulate an in-flight checkout by holding the exact per-user lock the action acquires.
    $lock = Cache::lock("commerce:checkout:user:{$user->id}", 30);
    expect($lock->get())->toBeTrue();

    try {
        expect(fn () => app(CheckoutAction::class)->executeByUserId($user->id))
            ->toThrow(CheckoutInProgressException::class);
    } finally {
        $lock->release();
    }

    // The blocked duplicate created no order (and therefore no gateway charge).
    expect(Order::where('user_id', $user->id)->count())->toBe(0);
});

it('a duplicate submit after the cart is captured does not create a second order', function () {
    [$course, $product] = courseProduct(19900);
    $user = User::factory()->create();
    Sanctum::actingAs($user);
    $this->postJson('/api/v1/cart', ['product' => $product->public_id])->assertOk();

    $this->postJson('/api/v1/checkout')->assertCreated();

    // The cart was emptied on capture, so a repeat submit is a safe 422 — not a second order.
    $this->postJson('/api/v1/checkout')->assertStatus(422)
        ->assertJsonPath('error.code', 'COMMERCE_CART_EMPTY');

    expect(Order::where('user_id', $user->id)->count())->toBe(1);
});
