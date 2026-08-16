<?php

declare(strict_types=1);

use App\Contexts\Commerce\Enums\SeatMode;
use App\Contexts\Commerce\Models\Cart;
use App\Platform\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

/**
 * `seat_mode = buyer_selects` promises the buyer picks how many seats they want, and nothing in the
 * purchase flow captures that: cart and order items carry no quantity, and no price row says whether
 * a chosen quantity multiplies the price or buys a pack at a flat rate.
 *
 * The seat wave silently fell back to the admin's default count, which sold a number the buyer never
 * chose. These tests pin the replacement behaviour — a clear refusal at every entry point — so the
 * fallback cannot quietly return.
 */
uses(RefreshDatabase::class);
require_once __DIR__.'/CommerceHelpers.php';

it('refuses to put a buyer-selected-seats product in the cart', function (): void {
    [, $product] = courseProduct(19900);
    // Sold to both, so the audience rule cannot be what refuses this — the seat mode is.
    $product->forceFill([
        'audience' => 'both',
        'seat_mode' => SeatMode::BuyerSelects->value,
        'default_seat_count' => 25,
    ])->save();

    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $this->postJson('/api/v1/cart', ['product' => $product->public_id])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'COMMERCE_SEAT_QUANTITY_UNAVAILABLE');

    $this->getJson('/api/v1/cart')->assertOk()->assertJsonCount(0, 'data.items');
});

it('refuses at checkout when a product becomes buyer-selected after it was added', function (): void {
    [, $product] = courseProduct(19900);
    $product->forceFill(['audience' => 'both', 'seat_mode' => SeatMode::Fixed->value])->save();

    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $this->postJson('/api/v1/cart', ['product' => $product->public_id])->assertOk();

    // The admin switches the product while the cart sits idle.
    $product->forceFill(['seat_mode' => SeatMode::BuyerSelects->value])->save();

    $this->postJson('/api/v1/checkout')
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'COMMERCE_SEAT_QUANTITY_UNAVAILABLE');

    // Nothing was ordered, and the cart is left intact for the buyer to sort out.
    expect(Cart::where('user_id', $user->id)->firstOrFail()->items()->count())->toBe(1);
});

it('still sells a fixed-seat product normally', function (): void {
    [, $product] = courseProduct(19900);
    $product->forceFill([
        'audience' => 'both',
        'seat_mode' => SeatMode::Fixed->value,
        'default_seat_count' => 10,
    ])->save();

    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $this->postJson('/api/v1/cart', ['product' => $product->public_id])->assertOk();
    $this->postJson('/api/v1/checkout')->assertCreated();
});
