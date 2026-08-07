<?php

use App\Contexts\Commerce\Enums\OrderStatus;
use App\Contexts\Commerce\Enums\RefundStatus;
use App\Contexts\Commerce\Filament\Resources\RefundResource\Pages\ViewRefund;
use App\Contexts\Commerce\Models\Order;
use App\Contexts\Commerce\Models\Refund;
use App\Platform\Identity\Database\Seeders\RolePermissionSeeder;
use App\Platform\Identity\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function refundDetailAdmin(): User
{
    test()->seed(RolePermissionSeeder::class);
    $admin = User::factory()->create();
    $admin->assignRole('super_admin');
    test()->actingAs($admin, 'web');
    Filament::setCurrentPanel(Filament::getPanel('admin'));

    return $admin;
}

/**
 * A refund against an order owned by a named customer.
 *
 * @param  array<string, mixed>  $refundOverrides
 */
function refundForCustomer(string $orderStatus, array $refundOverrides = []): Refund
{
    $user = User::factory()->create(['name' => 'Jane Buyer', 'email' => 'jane.buyer@example.test']);

    $order = Order::create([
        'user_id' => $user->id,
        'status' => $orderStatus,
        'currency' => 'SAR',
        'subtotal_minor' => 10000,
        'discount_minor' => 0,
        'tax_minor' => 0,
        'total_minor' => 10000,
        'placed_at' => now(),
    ]);
    $order->forceFill(['paid_at' => now()])->save();

    return Refund::create(array_replace([
        'order_id' => $order->getKey(),
        'amount_minor' => 10000,
        'currency' => 'SAR',
        'status' => RefundStatus::Succeeded->value,
        'reason' => 'requested_by_customer',
    ], $refundOverrides));
}

it('R1: shows the customer name and email on the refund detail', function () {
    refundDetailAdmin();
    $refund = refundForCustomer(OrderStatus::Refunded->value);

    Livewire::test(ViewRefund::class, ['record' => $refund->getAttribute('public_id')])
        ->assertSuccessful()
        ->assertSee('Jane Buyer')
        ->assertSee('jane.buyer@example.test');
});

it('R2: shows the captured/paid total alongside the refunded amount', function () {
    refundDetailAdmin();
    $refund = refundForCustomer(OrderStatus::Paid->value, ['amount_minor' => 4000]);

    Livewire::test(ViewRefund::class, ['record' => $refund->getAttribute('public_id')])
        ->assertSuccessful()
        ->assertSee('Captured / paid total (minor units)')
        ->assertSee('Refunded (minor units)')
        ->assertSee('Refundable remaining (minor units)');
});

it('R3: surfaces enrollment revocation for a fully-refunded order', function () {
    refundDetailAdmin();
    $refund = refundForCustomer(OrderStatus::Refunded->value);

    Livewire::test(ViewRefund::class, ['record' => $refund->getAttribute('public_id')])
        ->assertSuccessful()
        ->assertSee('Enrollment revoked (order fully refunded).');
});

it('R3: surfaces access retained for a partial refund (order still paid)', function () {
    refundDetailAdmin();
    $refund = refundForCustomer(OrderStatus::Paid->value, ['amount_minor' => 4000]);

    Livewire::test(ViewRefund::class, ['record' => $refund->getAttribute('public_id')])
        ->assertSuccessful()
        ->assertSee('Access retained (partial refund — order still paid).');
});

it('R3: reports no entitlement change when the refund has not succeeded', function () {
    refundDetailAdmin();
    $refund = refundForCustomer(OrderStatus::Paid->value, ['status' => RefundStatus::Failed->value, 'amount_minor' => 4000]);

    Livewire::test(ViewRefund::class, ['record' => $refund->getAttribute('public_id')])
        ->assertSuccessful()
        ->assertSee('No entitlement change (refund not succeeded).');
});
