<?php

declare(strict_types=1);

use App\Contexts\Commerce\Actions\Payment\FulfillOrderAction;
use App\Contexts\Commerce\Enums\PricingBasis;
use App\Contexts\Commerce\Enums\SeatMode;
use App\Contexts\Commerce\Models\Cart;
use App\Contexts\Commerce\Models\CompanyEntitlement;
use App\Contexts\Commerce\Models\Order;
use App\Domains\Crm\Enums\MemberRole;
use App\Domains\Crm\Enums\MemberStatus;
use App\Domains\Crm\Models\Organization;
use App\Domains\Crm\Models\OrganizationMember;
use App\Platform\Identity\Database\Seeders\RolePermissionSeeder;
use App\Platform\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

/**
 * `seat_mode = buyer_selects` means the company picks how many seats it wants, inside bounds the
 * admin set, and pays accordingly.
 *
 * Until this wave it was refused outright, because nothing captured a quantity and no price row
 * said what a quantity would cost. Both now exist, so these tests pin the three things that have to
 * agree: what the buyer is allowed to choose, what they are charged for it, and how many seats the
 * company actually receives. A drift between any two of those is a billing defect.
 */
uses(RefreshDatabase::class);
require_once __DIR__.'/CommerceHelpers.php';

beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);
});

/** A company buyer whose cart is switched to a company purchase, plus a per-seat product. */
function seatBuyer(array $policy = []): array
{
    [$course, $product] = courseProduct(40000);
    $product->forceFill(array_merge([
        'audience' => 'both',
        'seat_mode' => SeatMode::BuyerSelects->value,
        'pricing_basis' => PricingBasis::PerSeat->value,
        'min_seats' => 5,
        'max_seats' => 100,
        'seat_increment' => 5,
        'default_seat_count' => 10,
    ], $policy))->save();

    $user = User::factory()->create();
    $organization = Organization::factory()->create();
    OrganizationMember::create([
        'organization_id' => $organization->id,
        'user_id' => $user->id,
        'email' => $user->email,
        'role' => MemberRole::Owner->value,
        'status' => MemberStatus::Active->value,
        'joined_at' => now(),
    ]);
    $user->forceFill(['organization_id' => $organization->id])->save();

    Sanctum::actingAs($user);
    test()->putJson('/api/v1/cart/buyer', ['buyer_type' => 'company'])->assertOk();

    return [$product->refresh(), $user, $organization, $course];
}

it('sells a buyer-selected seat count and prices it per seat', function (): void {
    [$product] = seatBuyer();

    test()->postJson('/api/v1/cart', ['product' => $product->public_id, 'seats' => 25])
        ->assertOk()
        ->assertJsonPath('data.items.0.quantity', 25)
        ->assertJsonPath('data.items.0.unit_amount_minor', 40000)
        ->assertJsonPath('data.items.0.line_amount_minor', 1000000)
        ->assertJsonPath('data.subtotal_minor', 1000000);
});

it('charges the package price once when the basis is not per seat', function (): void {
    [$product] = seatBuyer(['pricing_basis' => PricingBasis::FixedBundlePrice->value]);

    // The seat count still decides the pool size — it just does not multiply the price.
    test()->postJson('/api/v1/cart', ['product' => $product->public_id, 'seats' => 25])
        ->assertOk()
        ->assertJsonPath('data.items.0.quantity', 25)
        ->assertJsonPath('data.subtotal_minor', 40000);
});

it('refuses a count below the minimum, above the maximum, or off the step', function (): void {
    [$product] = seatBuyer();

    foreach ([1, 200, 7] as $seats) {
        test()->postJson('/api/v1/cart', ['product' => $product->public_id, 'seats' => $seats])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'COMMERCE_SEAT_QUANTITY_INVALID')
            // The bounds travel with the refusal so the buy box can say what IS allowed.
            ->assertJsonPath('error.details.min', 5)
            ->assertJsonPath('error.details.max', 100)
            ->assertJsonPath('error.details.increment', 5);
    }

    test()->getJson('/api/v1/cart')->assertOk()->assertJsonCount(0, 'data.items');
});

it('refuses to guess when no count was supplied', function (): void {
    [$product] = seatBuyer();

    // A default would be a number the buyer never agreed to pay for.
    test()->postJson('/api/v1/cart', ['product' => $product->public_id])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'COMMERCE_SEAT_QUANTITY_INVALID');
});

it('refuses an individual buying a product sold by the seat', function (): void {
    [$product] = seatBuyer();
    test()->putJson('/api/v1/cart/buyer', ['buyer_type' => 'individual'])->assertOk();

    test()->postJson('/api/v1/cart', ['product' => $product->public_id, 'seats' => 25])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'COMMERCE_SEAT_QUANTITY_INVALID');
});

it('keeps a quote-only product out of self-service entirely', function (): void {
    [$product] = seatBuyer(['seat_mode' => SeatMode::QuoteOnly->value]);

    test()->postJson('/api/v1/cart', ['product' => $product->public_id, 'seats' => 25])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'COMMERCE_SEAT_QUANTITY_UNAVAILABLE');
});

it('re-checks the count at checkout against the product as it stands then', function (): void {
    [$product, $user] = seatBuyer();

    test()->postJson('/api/v1/cart', ['product' => $product->public_id, 'seats' => 25])->assertOk();

    // The admin raises the minimum above what is already in the cart.
    $product->forceFill(['min_seats' => 50])->save();

    test()->postJson('/api/v1/checkout')
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'COMMERCE_SEAT_QUANTITY_INVALID');

    // Nothing was ordered, and the cart is left intact for the buyer to sort out.
    expect(Cart::where('user_id', $user->id)->firstOrFail()->items()->count())->toBe(1);
});

it('gives the company exactly the seats it bought', function (): void {
    [$product, , $organization] = seatBuyer();

    test()->postJson('/api/v1/cart', ['product' => $product->public_id, 'seats' => 25])->assertOk();
    test()->postJson('/api/v1/checkout')->assertCreated();

    // Fulfilment is what turns the paid order into seats; drive it the way payment does — paid,
    // with the terms accepted, which is the gate FulfillOrderAction waits on.
    $order = Order::query()->latest('id')->firstOrFail();
    $order->forceFill(['status' => 'paid', 'paid_at' => now()])->save();
    $order->contract?->forceFill(['status' => 'accepted', 'accepted_at' => now()])->save();
    app(FulfillOrderAction::class)->execute($order->refresh());

    $entitlement = CompanyEntitlement::where('organization_id', $organization->id)->firstOrFail();

    expect($entitlement->seats_purchased)->toBe(25)
        // Not the admin's default of 10 — the pool is the size the buyer paid for.
        ->and($entitlement->seat_mode)->toBe(SeatMode::BuyerSelects);
});

it('records the seat count on the order so an invoice can be rebuilt from it', function (): void {
    [$product] = seatBuyer();

    test()->postJson('/api/v1/cart', ['product' => $product->public_id, 'seats' => 25])->assertOk();
    test()->postJson('/api/v1/checkout')->assertCreated();

    $order = Order::query()->latest('id')->with('items')->firstOrFail();

    expect($order->items->first()->quantityOrOne())->toBe(25)
        ->and($order->items->first()->unit_amount_minor)->toBe(40000)
        ->and($order->subtotal_minor)->toBe(1000000);
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

    test()->postJson('/api/v1/cart', ['product' => $product->public_id])->assertOk();
    test()->postJson('/api/v1/checkout')->assertCreated();
});
