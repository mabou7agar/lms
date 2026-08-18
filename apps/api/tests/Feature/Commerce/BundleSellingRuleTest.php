<?php

declare(strict_types=1);

use App\Contexts\Commerce\Actions\Payment\FulfillOrderAction;
use App\Contexts\Commerce\Enums\PricingBasis;
use App\Contexts\Commerce\Enums\ProductAudience;
use App\Contexts\Commerce\Enums\ProductType;
use App\Contexts\Commerce\Enums\SeatMode;
use App\Contexts\Commerce\Models\CompanyEntitlement;
use App\Contexts\Commerce\Models\Order;
use App\Contexts\Commerce\Models\Product;
use App\Contexts\Learning\Models\Enrollment;
use App\Domains\Catalog\Models\Course;
use App\Domains\Crm\Enums\MemberRole;
use App\Domains\Crm\Enums\MemberStatus;
use App\Domains\Crm\Models\Organization;
use App\Domains\Crm\Models\OrganizationMember;
use App\Platform\Identity\Database\Seeders\RolePermissionSeeder;
use App\Platform\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

/**
 * How a bundle is allowed to be sold.
 *
 * A bundle offered to "individuals and companies" has to be buyable by both. A per-seat price is
 * not: there is one amount on the product and it means the price of ONE seat, and seats exist only
 * inside a company purchase. So a per-seat bundle advertised to individuals puts a Buy button on
 * the public page that the cart then refuses — a dead path, and the worst kind, because the buyer
 * only finds out after choosing.
 *
 * These tests pin the rule at all three layers it has to hold: what the admin may save, what the
 * public API advertises, and what the cart accepts. Plus the two outcomes the rule exists to
 * protect — an individual bundle purchase enrolling the buyer in every included course, and a
 * company bundle purchase producing seats a manager can hand out.
 */
uses(RefreshDatabase::class);
require_once __DIR__.'/CommerceHelpers.php';

beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);
});

/** A bundle of two published courses, priced once in SAR. */
function bundleOfTwoCourses(array $policy = []): array
{
    [$courseA, $product] = courseProduct(129900);
    $product->forceFill(array_merge([
        'type' => ProductType::Bundle->value,
        'audience' => ProductAudience::Both->value,
    ], $policy))->save();

    $courseB = Course::factory()->published()->create();
    $product->courses()->syncWithoutDetaching([$courseB->id]);

    return [$product->refresh(), $courseA, $courseB];
}

/** An org owner acting on a cart already switched to a company purchase. */
function companyBuyer(): array
{
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

    return [$user, $organization];
}

it('reads a per-seat bundle as sold to companies whatever the audience column says', function (): void {
    [$product] = bundleOfTwoCourses([
        'pricing_basis' => PricingBasis::PerSeat->value,
        'seat_mode' => SeatMode::BuyerSelects->value,
        'min_seats' => 5,
        'max_seats' => 100,
    ]);

    expect($product->audience)->toBe(ProductAudience::Both)
        ->and($product->effectiveAudience())->toBe(ProductAudience::Company);
});

it('leaves a fixed-price bundle sold to both buyer types', function (): void {
    [$product] = bundleOfTwoCourses(['pricing_basis' => PricingBasis::FixedBundlePrice->value]);

    expect($product->effectiveAudience())->toBe(ProductAudience::Both);
});

it('does not advertise the individual path on a per-seat bundle', function (): void {
    [$product] = bundleOfTwoCourses([
        'pricing_basis' => PricingBasis::PerSeat->value,
        'seat_mode' => SeatMode::BuyerSelects->value,
        'min_seats' => 5,
    ]);

    // The public badge on the bundle card is drawn straight from this value.
    test()->getJson('/api/v1/products/'.$product->public_id)
        ->assertOk()
        ->assertJsonPath('data.audience', 'company');
});

it('keeps an individual out of a per-seat bundle with a reason they can act on', function (): void {
    [$product] = bundleOfTwoCourses([
        'pricing_basis' => PricingBasis::PerSeat->value,
        'seat_mode' => SeatMode::BuyerSelects->value,
        'min_seats' => 5,
        'max_seats' => 100,
    ]);

    Sanctum::actingAs(User::factory()->create());

    test()->postJson('/api/v1/cart', ['product' => $product->public_id, 'seats' => 10])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'COMMERCE_BUYER_AUDIENCE_MISMATCH');
});

it('grants every course in the bundle to an individual who buys it', function (): void {
    [$product, $courseA, $courseB] = bundleOfTwoCourses(['pricing_basis' => PricingBasis::FixedBundlePrice->value]);

    $user = User::factory()->create();
    Sanctum::actingAs($user);

    test()->postJson('/api/v1/cart', ['product' => $product->public_id])->assertOk();
    test()->postJson('/api/v1/checkout')->assertCreated();

    // Fulfilment is what turns a paid order into access; drive it the way payment does.
    $order = Order::query()->where('user_id', $user->id)->latest('id')->firstOrFail();
    $order->forceFill(['status' => 'paid', 'paid_at' => now()])->save();
    $order->contract?->forceFill(['status' => 'accepted', 'accepted_at' => now()])->save();
    app(FulfillOrderAction::class)->execute($order->refresh());

    // The whole point of a bundle: one purchase, every included course, enrolled to the buyer.
    expect(Enrollment::query()->where('user_id', $user->id)->pluck('course_id')->sort()->values()->all())
        ->toEqual(collect([$courseA->id, $courseB->id])->sort()->values()->all());
});

it('creates assignable seats when a company buys the bundle', function (): void {
    [$product] = bundleOfTwoCourses([
        'pricing_basis' => PricingBasis::PerSeat->value,
        'seat_mode' => SeatMode::BuyerSelects->value,
        'min_seats' => 5,
        'max_seats' => 100,
        'seat_increment' => 5,
    ]);

    [, $organization] = companyBuyer();

    test()->postJson('/api/v1/cart', ['product' => $product->public_id, 'seats' => 10])->assertOk();
    test()->postJson('/api/v1/checkout')->assertCreated();

    $order = Order::query()->where('organization_id', $organization->id)->latest('id')->firstOrFail();
    $order->forceFill(['status' => 'paid', 'paid_at' => now()])->save();
    $order->contract?->forceFill(['status' => 'accepted', 'accepted_at' => now()])->save();
    app(FulfillOrderAction::class)->execute($order->refresh());

    // Seats the manager can hand out — the company half of the same rule.
    $entitlement = CompanyEntitlement::query()->where('organization_id', $organization->id)->firstOrFail();
    expect($entitlement->seats_purchased)->toBe(10);
});
