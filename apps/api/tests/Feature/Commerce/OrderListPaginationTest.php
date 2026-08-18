<?php

declare(strict_types=1);

use App\Contexts\Commerce\Models\Order;
use App\Platform\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

/**
 * The orders list is paginated, so it must answer in the paginated envelope.
 *
 * It was wrapped in the plain success envelope, which emits `data` and nothing else. The client
 * reads `meta.current_page` to render the pager, so the page died with "Cannot read properties of
 * undefined" — on the very page the checkout success screen offers as a destination.
 */
uses(RefreshDatabase::class);

function orderFor(User $user): Order
{
    return Order::create([
        'user_id' => $user->id,
        'status' => 'paid',
        'currency' => 'SAR',
        'subtotal_minor' => 19900,
        'discount_minor' => 0,
        'tax_minor' => 2985,
        'total_minor' => 22885,
        'placed_at' => now(),
        'paid_at' => now(),
    ]);
}

it('returns the paginated envelope, not a bare data array', function (): void {
    $user = User::factory()->create();
    orderFor($user);
    Sanctum::actingAs($user);

    $this->getJson('/api/v1/orders')
        ->assertOk()
        ->assertJsonStructure([
            'data' => [['id', 'status', 'total_minor']],
            'meta' => ['current_page', 'per_page', 'total', 'last_page'],
        ])
        ->assertJsonPath('meta.current_page', 1)
        ->assertJsonPath('meta.total', 1);
});

it('still carries the pager when the buyer has no orders at all', function (): void {
    Sanctum::actingAs(User::factory()->create());

    // An empty list must not be an absent pager: the page renders the pager either way.
    $this->getJson('/api/v1/orders')
        ->assertOk()
        ->assertJsonPath('meta.total', 0)
        ->assertJsonPath('meta.current_page', 1);
});

it('shows a buyer only their own orders', function (): void {
    $mine = User::factory()->create();
    orderFor($mine);
    orderFor(User::factory()->create());

    Sanctum::actingAs($mine);

    $this->getJson('/api/v1/orders')->assertOk()->assertJsonPath('meta.total', 1);
});
