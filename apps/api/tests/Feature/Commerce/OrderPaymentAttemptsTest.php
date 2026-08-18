<?php

use App\Contexts\Commerce\Enums\OrderStatus;
use App\Contexts\Commerce\Enums\PaymentAttemptStatus;
use App\Contexts\Commerce\Filament\Resources\OrderResource;
use App\Contexts\Commerce\Filament\Resources\OrderResource\Pages\ViewOrder;
use App\Contexts\Commerce\Models\Order;
use App\Contexts\Commerce\Models\PaymentAttempt;
use App\Platform\Identity\Database\Seeders\RolePermissionSeeder;
use App\Platform\Identity\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/** Sign in as a super_admin on the admin panel so the order detail page is reachable. */
function actAsOrderAdmin(): User
{
    test()->seed(RolePermissionSeeder::class);
    $admin = User::factory()->create();
    $admin->assignRole('super_admin');
    test()->actingAs($admin, 'web');
    Filament::setCurrentPanel(Filament::getPanel('admin'));

    return $admin;
}

/** A failed order carrying two payment attempts (one failed, one abandoned). */
function orderWithAttempts(): Order
{
    $user = User::factory()->create();

    $order = Order::create([
        'user_id' => $user->id,
        'status' => OrderStatus::Failed->value,
        'currency' => 'SAR',
        'subtotal_minor' => 10000,
        'discount_minor' => 0,
        'tax_minor' => 0,
        'total_minor' => 10000,
        'placed_at' => now(),
    ]);

    PaymentAttempt::create([
        'order_id' => $order->getKey(),
        'provider' => 'fake',
        'provider_reference' => 'ch_live_TOKEN9999',
        'status' => PaymentAttemptStatus::Failed->value,
        'amount_minor' => 10000,
        'currency' => 'SAR',
        'error_code' => 'card_declined',
        'attempt_no' => 1,
    ]);
    PaymentAttempt::create([
        'order_id' => $order->getKey(),
        'provider' => 'fake',
        'provider_reference' => null,
        'status' => PaymentAttemptStatus::Abandoned->value,
        'amount_minor' => 10000,
        'currency' => 'SAR',
        'error_code' => 'retry_failed',
        'attempt_no' => 2,
    ]);

    return $order;
}

it('S1: renders the payment-attempts trail on the order detail with attempt numbers and statuses', function () {
    actAsOrderAdmin();
    $order = orderWithAttempts();

    Livewire::test(ViewOrder::class, ['record' => $order->getAttribute('public_id')])
        ->assertSuccessful()
        ->assertSee('Payment attempts')
        ->assertSee('card_declined')
        ->assertSee('retry_failed');
});

it('S1: masks the provider reference and never renders the raw token', function () {
    actAsOrderAdmin();
    $order = orderWithAttempts();

    Livewire::test(ViewOrder::class, ['record' => $order->getAttribute('public_id')])
        ->assertSuccessful()
        ->assertDontSee('TOKEN')
        ->assertSee('9999');
});

it('S1: the order surface exposes no mutation page for attempts (read-only)', function () {
    actAsOrderAdmin();
    $order = orderWithAttempts();

    // The order detail is a ViewRecord: only index + view pages are registered — no edit/create page,
    // and the payment-attempts section is a read-only infolist (the form schema is empty).
    $pages = OrderResource::getPages();

    expect(array_keys($pages))->toBe(['index', 'view']);

    Livewire::test(ViewOrder::class, ['record' => $order->getAttribute('public_id')])
        ->assertSuccessful()
        ->assertSee('Payment attempts');
});
